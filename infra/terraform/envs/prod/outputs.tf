output "public_ip" {
  value = module.box.public_ip
}

output "instance_id" {
  value = module.box.instance_id
}

output "artifacts_bucket" {
  value = module.storage.artifacts_bucket
}

output "backups_bucket" {
  value = module.storage.backups_bucket
}

output "media_bucket" {
  value = module.storage.media_bucket
}

output "ecr_repository_urls" {
  value = module.ecr.repository_urls
}

output "secret_name" {
  value = module.secrets.secret_name
}

output "domain" {
  value = var.domain
}

output "release_param" {
  value = module.box.release_param
}
