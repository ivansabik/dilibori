output "vpc_id" {
  value = aws_vpc.this.id
}

output "public_subnet_id" {
  value = aws_subnet.public.id
}

output "box_security_group_id" {
  value = aws_security_group.box.id
}

output "availability_zone" {
  value = aws_subnet.public.availability_zone
}
