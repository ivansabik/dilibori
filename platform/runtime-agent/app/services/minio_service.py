from __future__ import annotations

import json
import re
from pathlib import Path

from minio import Minio
from minio.error import S3Error

from app.config import Settings


_BUCKET_PATTERN = re.compile(r"^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$")


class MinioService:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        endpoint = settings.minio_endpoint.replace("https://", "").replace("http://", "")
        self._client = Minio(
            endpoint,
            access_key=settings.minio_root_user,
            secret_key=settings.minio_root_password,
            secure=settings.minio_secure,
        )

    def _policy_document(self, bucket: str, prefix: str) -> dict:
        prefix = prefix.strip("/")
        return {
            "Version": "2012-10-17",
            "Statement": [
                {
                    "Effect": "Allow",
                    "Action": [
                        "s3:GetObject",
                        "s3:PutObject",
                        "s3:DeleteObject",
                    ],
                    "Resource": [f"arn:aws:s3:::{bucket}/{prefix}/*"],
                },
                {
                    "Effect": "Allow",
                    "Action": ["s3:ListBucket"],
                    "Resource": [f"arn:aws:s3:::{bucket}"],
                    "Condition": {"StringLike": {"s3:prefix": [f"{prefix}/*", prefix]}},
                },
            ],
        }

    def create_tenant_credentials(
        self,
        *,
        deployment_id: str,
        access_key: str,
        secret_key: str,
        bucket: str,
        prefix: str,
    ) -> dict:
        if not _BUCKET_PATTERN.match(bucket):
            raise ValueError("bucket must be a valid S3 bucket name")
        prefix = prefix.strip("/")
        if not prefix:
            raise ValueError("prefix must be non-empty")

        policy_name = f"dilibori-{deployment_id[:24]}"
        policy = self._policy_document(bucket, prefix)
        policy_path = (
            Path(self._settings.data_dir) / "minio-policies" / f"{deployment_id}.json"
        )
        policy_path.parent.mkdir(parents=True, exist_ok=True)
        policy_path.write_text(
            json.dumps(
                {
                    "deployment_id": deployment_id,
                    "access_key": access_key,
                    "bucket": bucket,
                    "prefix": prefix,
                    "policy_name": policy_name,
                    "policy": policy,
                },
                indent=2,
            ),
            encoding="utf-8",
        )

        if not self._client.bucket_exists(bucket):
            self._client.make_bucket(bucket)

        applied = False
        apply_error: str | None = None
        try:
            self._client.set_bucket_policy(bucket, json.dumps(policy))
            applied = True
        except S3Error as exc:
            apply_error = str(exc)

        return {
            "policy_path": str(policy_path),
            "policy_applied_to_bucket": applied,
            "apply_error": apply_error,
            "note": (
                "Access key pair must be created with mc admin user add or your MinIO "
                "operator workflow. Policy JSON is on disk for manual attach."
            ),
        }
