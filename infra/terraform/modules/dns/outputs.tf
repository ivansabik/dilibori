output "zone_id" {
  value = local.zone_id
}

output "env_domain" {
  value = local.env_domain
}

output "wildcard_fqdn" {
  value = "*.${local.env_domain}"
}
