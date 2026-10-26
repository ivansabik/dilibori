output "instance_id" {
  value = aws_instance.box.id
}

output "public_ip" {
  value = aws_eip.box.public_ip
}

output "eip_allocation_id" {
  value = aws_eip.box.id
}

output "data_volume_id" {
  value = aws_ebs_volume.data.id
}

output "release_param" {
  value = aws_ssm_parameter.release_sha.name
}

output "instance_role_name" {
  value = aws_iam_role.box.name
}
