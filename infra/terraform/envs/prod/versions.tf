terraform {
  required_version = ">= 1.5.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }

  # After bootstrap, uncomment and fill from bootstrap outputs:
  # backend "s3" {
  #   bucket         = "dilibori-prod-tfstate-ACCOUNT"
  #   key            = "platform/terraform.tfstate"
  #   region         = "us-east-1"
  #   dynamodb_table = "dilibori-prod-tf-lock"
  #   encrypt        = true
  # }
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "dilibori"
      Environment = "prod"
      ManagedBy   = "terraform"
    }
  }
}
