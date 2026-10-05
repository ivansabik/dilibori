data "aws_route53_zone" "this" {
  count        = var.zone_id == "" ? 1 : 0
  name         = var.domain
  private_zone = false
}

locals {
  zone_id    = var.zone_id != "" ? var.zone_id : data.aws_route53_zone.this[0].zone_id
  env_domain = var.domain
}

resource "aws_route53_record" "wildcard" {
  zone_id = local.zone_id
  name    = "*.${local.env_domain}"
  type    = "A"
  ttl     = 60
  records = [var.eip_public_ip]
}

resource "aws_route53_record" "apex" {
  zone_id = local.zone_id
  name    = local.env_domain
  type    = "A"
  ttl     = 60
  records = [var.eip_public_ip]
}
