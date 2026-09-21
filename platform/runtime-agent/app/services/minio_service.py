from __future__ import annotations

import json
import logging
import re
import shutil
import subprocess
from pathlib import Path

from minio import Minio

from app.config import Settings

logger = logging.getLogger(__name__)

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
        scheme = "https" if settings.minio_secure else "http"
        self._endpoint_url = f"{scheme}://{endpoint}"

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

    def _mc(self, *args: str) -> None:
        mc = shutil.which("mc")
        if not mc:
            raise RuntimeError(
                "minio client (mc) is required to create tenant users; "
                "install it in the runtime-agent image"
            )
        result = subprocess.run(
            [mc, *args],
            capture_output=True,
            text=True,
            check=False,
        )
        if result.returncode != 0:
            detail = (result.stderr or result.stdout or "").strip()
            raise RuntimeError(f"mc {' '.join(args[:3])}… failed: {detail}")

    def _ensure_alias(self) -> str:
        alias = "dilibori-local"
        self._mc(
            "alias",
            "set",
            alias,
            self._endpoint_url,
            self._settings.minio_root_user,
            self._settings.minio_root_password,
        )
        return alias

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
        if not access_key or not secret_key:
            raise ValueError("access_key and secret_key are required")

        policy_name = f"dilibori-{deployment_id[:24]}"
        policy = self._policy_document(bucket, prefix)
        policy_path = (
            Path(self._settings.data_dir) / "minio-policies" / f"{deployment_id}.json"
        )
        policy_path.parent.mkdir(parents=True, exist_ok=True)
        # Policy body alone for `mc admin policy create`; meta kept alongside.
        policy_body_path = policy_path.with_suffix(".policy.json")
        policy_body_path.write_text(json.dumps(policy, indent=2), encoding="utf-8")
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

        alias = self._ensure_alias()
        # Idempotent policy: remove stale definition, then create + attach.
        subprocess.run(
            [
                shutil.which("mc") or "mc",
                "admin",
                "policy",
                "remove",
                alias,
                policy_name,
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        self._mc("admin", "policy", "create", alias, policy_name, str(policy_body_path))
        # user add fails if the key exists; remove then add so secret rotates cleanly.
        remove = subprocess.run(
            [shutil.which("mc") or "mc", "admin", "user", "remove", alias, access_key],
            capture_output=True,
            text=True,
            check=False,
        )
        if remove.returncode not in (0, 1):
            logger.warning("mc admin user remove: %s", (remove.stderr or "").strip())
        self._mc("admin", "user", "add", alias, access_key, secret_key)
        self._mc(
            "admin",
            "policy",
            "attach",
            alias,
            policy_name,
            "--user",
            access_key,
        )

        return {
            "policy_path": str(policy_path),
            "policy_name": policy_name,
            "access_key": access_key,
            "bucket": bucket,
            "prefix": prefix,
            "user_created": True,
            "policy_attached": True,
        }
