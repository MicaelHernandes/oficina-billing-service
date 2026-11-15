locals {
  tags = {
    Project   = var.project
    ManagedBy = "terraform"
    Repo      = "oficina-billing-service"
  }

  table_name = "${var.project}-billing"
  topic_name = "${var.project}-billing-events"
  queue_name = "${var.project}-${var.service_name}"

  # arn:aws:iam::<conta>:oidc-provider/<issuer sem https://>
  oidc_provider_arn = data.aws_ssm_parameter.eks_oidc_provider_arn.value
  oidc_issuer       = replace(local.oidc_provider_arn, "/^arn:aws:iam::[0-9]+:oidc-provider\\//", "")
}
