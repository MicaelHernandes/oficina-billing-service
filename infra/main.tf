# ---------------------------------------------------------------------------
# Billing Service.
#
# - DynamoDB `oficina-billing` (NoSQL do serviço): tabela única com
#   orçamentos, cobranças, inbox e outbox; GSI1 por OS e GSI2 esparso com o
#   outbox pendente. On-demand e TTL em `expires_at` (inbox/outbox antigos).
# - Fila SQS `oficina-billing-service` + DLQ, assinando o tópico do OS
#   Service só com os comandos `billing.*` (filter policy).
# - Tópico SNS `oficina-billing-events`: eventos para o orquestrador.
# - Role IRSA do ServiceAccount `oficina-billing`.
# - Segredos: token das rotas internas (gerado) e credenciais do Mercado Pago
#   (o valor é cadastrado fora do Terraform, para não ir para o state).
# ---------------------------------------------------------------------------

resource "aws_dynamodb_table" "billing" {
  name         = local.table_name
  billing_mode = "PAY_PER_REQUEST"
  hash_key     = "PK"
  range_key    = "SK"

  dynamic "attribute" {
    for_each = ["PK", "SK", "GSI1PK", "GSI1SK", "GSI2PK", "GSI2SK"]
    content {
      name = attribute.value
      type = "S"
    }
  }

  global_secondary_index {
    name            = "GSI1"
    hash_key        = "GSI1PK"
    range_key       = "GSI1SK"
    projection_type = "ALL"
  }

  global_secondary_index {
    name            = "GSI2"
    hash_key        = "GSI2PK"
    range_key       = "GSI2SK"
    projection_type = "ALL"
  }

  ttl {
    attribute_name = "expires_at"
    enabled        = true
  }

  point_in_time_recovery {
    enabled = true
  }

  server_side_encryption {
    enabled = true
  }
}

resource "aws_sns_topic" "events" {
  name = local.topic_name
  # Criptografia em repouso com a chave gerenciada da AWS (sem custo de CMK).
  kms_master_key_id = "alias/aws/sns"
}

resource "aws_sqs_queue" "dlq" {
  name                      = "${local.queue_name}-dlq"
  message_retention_seconds = 1209600 # 14 dias para investigar/reprocessar
}

resource "aws_sqs_queue" "inbox" {
  name                       = local.queue_name
  visibility_timeout_seconds = 60
  receive_wait_time_seconds  = 20
  message_retention_seconds  = 345600 # 4 dias

  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.dlq.arn
    maxReceiveCount     = var.max_receive_count
  })
}

resource "aws_sqs_queue_redrive_allow_policy" "dlq" {
  queue_url = aws_sqs_queue.dlq.id

  redrive_allow_policy = jsonencode({
    redrivePermission = "byQueue"
    sourceQueueArns   = [aws_sqs_queue.inbox.arn]
  })
}

# Só o tópico do OS Service pode entregar na fila.
data "aws_iam_policy_document" "inbox" {
  statement {
    effect    = "Allow"
    actions   = ["sqs:SendMessage"]
    resources = [aws_sqs_queue.inbox.arn]

    principals {
      type        = "Service"
      identifiers = ["sns.amazonaws.com"]
    }

    condition {
      test     = "ArnEquals"
      variable = "aws:SourceArn"
      values   = [data.aws_ssm_parameter.os_topic_arn.value]
    }
  }
}

resource "aws_sqs_queue_policy" "inbox" {
  queue_url = aws_sqs_queue.inbox.id
  policy    = data.aws_iam_policy_document.inbox.json
}

resource "aws_sns_topic_subscription" "os_commands" {
  topic_arn            = data.aws_ssm_parameter.os_topic_arn.value
  protocol             = "sqs"
  endpoint             = aws_sqs_queue.inbox.arn
  raw_message_delivery = true

  # Do tópico do orquestrador, só os comandos destinados ao Billing.
  filter_policy = jsonencode({
    type = [{ prefix = "billing." }]
  })

  depends_on = [aws_sqs_queue_policy.inbox]
}

# --- IRSA -----------------------------------------------------------------
data "aws_iam_policy_document" "irsa_trust" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [local.oidc_provider_arn]
    }

    condition {
      test     = "StringEquals"
      variable = "${local.oidc_issuer}:sub"
      values   = ["system:serviceaccount:${var.k8s_namespace}:${var.k8s_service_account}"]
    }

    condition {
      test     = "StringEquals"
      variable = "${local.oidc_issuer}:aud"
      values   = ["sts.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "irsa" {
  name               = "${var.project}-${var.service_name}-irsa"
  assume_role_policy = data.aws_iam_policy_document.irsa_trust.json
}

data "aws_iam_policy_document" "irsa" {
  statement {
    effect = "Allow"
    actions = [
      "dynamodb:GetItem",
      "dynamodb:PutItem",
      "dynamodb:UpdateItem",
      "dynamodb:Query",
      "dynamodb:ConditionCheckItem",
    ]
    resources = [aws_dynamodb_table.billing.arn, "${aws_dynamodb_table.billing.arn}/index/*"]
  }

  statement {
    effect    = "Allow"
    actions   = ["sns:Publish"]
    resources = [aws_sns_topic.events.arn]
  }

  statement {
    effect = "Allow"
    actions = [
      "sqs:ReceiveMessage",
      "sqs:DeleteMessage",
      "sqs:ChangeMessageVisibility",
      "sqs:GetQueueAttributes",
    ]
    resources = [aws_sqs_queue.inbox.arn]
  }
}

resource "aws_iam_role_policy" "irsa" {
  name   = "${var.project}-${var.service_name}-access"
  role   = aws_iam_role.irsa.id
  policy = data.aws_iam_policy_document.irsa.json
}

# --- Segredos ---------------------------------------------------------------
resource "random_password" "internal_token" {
  length  = 40
  special = false
}

resource "aws_secretsmanager_secret" "internal_token" {
  name = "/oficina/billing/internal-token"
}

resource "aws_secretsmanager_secret_version" "internal_token" {
  secret_id     = aws_secretsmanager_secret.internal_token.id
  secret_string = random_password.internal_token.result
}

# Valor cadastrado à mão (JSON {"access_token": "...", "webhook_secret": "..."}):
#   aws secretsmanager put-secret-value --secret-id /oficina/billing/mercadopago --secret-string '{...}'
# Sem valor, a pipeline sobe o Billing com o checkout simulado (PAYMENT_DRIVER=fake).
resource "aws_secretsmanager_secret" "mercadopago" {
  name = "/oficina/billing/mercadopago"
}
