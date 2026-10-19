# ---------------------------------------------------------------------------
# Outputs + SSM (/oficina/messaging/billing-service/...): a pipeline lê para
# configurar os pods, e a infra do OS Service lê o ARN do tópico para assinar
# os eventos (consumed_topics).
# ---------------------------------------------------------------------------

output "table_name" {
  description = "Tabela DynamoDB do Billing."
  value       = aws_dynamodb_table.billing.name
}

output "topic_arn" {
  description = "ARN do tópico SNS do Billing."
  value       = aws_sns_topic.events.arn
}

output "queue_url" {
  description = "URL da fila SQS do Billing."
  value       = aws_sqs_queue.inbox.id
}

output "dlq_url" {
  description = "URL da DLQ do Billing."
  value       = aws_sqs_queue.dlq.id
}

output "irsa_role_arn" {
  description = "Role IRSA do ServiceAccount oficina-billing."
  value       = aws_iam_role.irsa.arn
}

resource "aws_ssm_parameter" "topic_arn" {
  name  = "/oficina/messaging/${var.service_name}/topic_arn"
  type  = "String"
  value = aws_sns_topic.events.arn
}

resource "aws_ssm_parameter" "queue_url" {
  name  = "/oficina/messaging/${var.service_name}/queue_url"
  type  = "String"
  value = aws_sqs_queue.inbox.id
}

resource "aws_ssm_parameter" "irsa_role_arn" {
  name  = "/oficina/messaging/${var.service_name}/irsa_role_arn"
  type  = "String"
  value = aws_iam_role.irsa.arn
}
