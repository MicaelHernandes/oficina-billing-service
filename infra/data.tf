# OIDC provider do EKS (repo oficina-k8s-infra) para o IRSA.
data "aws_ssm_parameter" "eks_oidc_provider_arn" {
  name = "/oficina/eks/oidc_provider_arn"
}

# Tópico do orquestrador (infra/ do oficina-api), de onde vêm os comandos
# billing.*. Por isso a infra do OS Service precisa ser aplicada antes.
data "aws_ssm_parameter" "os_topic_arn" {
  name = "/oficina/messaging/os-service/topic_arn"
}
