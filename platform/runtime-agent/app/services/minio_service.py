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
        access = (settings.minio_root_user or "").strip()
        secret = (settings.minio_root_password or "").strip()
        if access and secret:
            self._client = Minio(
                endpoint,
                access_key=access,
                secret_key=secret,
                secure=settings.minio_secure,
            )
        else:
            # Instance-role / default credential chain (AWS).
            from minio.credentials import IamAwsProvider

            self._client = Minio(
                endpoint,
                credentials=IamAwsProvider(),
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

    def _identity_mode(self) -> str:
        mode = (self._settings.object_store_identity or "minio").strip().lower()
        return mode if mode in {"minio", "iam"} else "minio"

    def _iam_user_name(self, deployment_id: str) -> str:
        # IAM user names allow path; keep short alphanumeric suffix.
        short = deployment_id.replace("-", "")[:12]
        return f"dilibori-tenant-{short}"

    def _iam_user_path(self) -> str:
        path = (self._settings.object_store_iam_path or "dilibori/local/tenants/").strip()
        if not path.startswith("/"):
            path = f"/{path}"
        if not path.endswith("/"):
            path = f"{path}/"
        return path

    def _create_iam_identity(
        self,
        *,
        deployment_id: str,
        bucket: str,
        prefix: str,
        policy: dict,
    ) -> dict[str, str]:
        # ponytail: one IAM user per tenant (5000-user account quota, long-lived
        # keys). Upgrade path: STS AssumeRole with a per-tenant session policy.
        import boto3
        from botocore.exceptions import ClientError

        iam = boto3.client("iam")
        user_name = self._iam_user_name(deployment_id)
        user_path = self._iam_user_path()
        policy_name = "dilibori-media-prefix"

        try:
            iam.create_user(
                UserName=user_name,
                Path=user_path,
                Tags=[
                    {"Key": "dilibori:deployment_id", "Value": deployment_id},
                    {"Key": "dilibori:managed_by", "Value": "runtime-agent"},
                ],
            )
        except ClientError as exc:
            if exc.response.get("Error", {}).get("Code") != "EntityAlreadyExists":
                raise

        iam.put_user_policy(
            UserName=user_name,
            PolicyName=policy_name,
            PolicyDocument=json.dumps(policy),
        )

        # Rotate keys: delete existing, create one.
        for key in iam.list_access_keys(UserName=user_name).get("AccessKeyMetadata", []):
            iam.delete_access_key(UserName=user_name, AccessKeyId=key["AccessKeyId"])
        created = iam.create_access_key(UserName=user_name)["AccessKey"]
        return {
            "access_key": created["AccessKeyId"],
            "secret_key": created["SecretAccessKey"],
            "iam_user": user_name,
            "policy_name": policy_name,
        }

    def _delete_iam_identity(self, *, deployment_id: str) -> None:
        import boto3
        from botocore.exceptions import ClientError

        iam = boto3.client("iam")
        user_name = self._iam_user_name(deployment_id)
        try:
            for key in iam.list_access_keys(UserName=user_name).get(
                "AccessKeyMetadata", []
            ):
                iam.delete_access_key(
                    UserName=user_name, AccessKeyId=key["AccessKeyId"]
                )
            for policy in iam.list_user_policies(UserName=user_name).get(
                "PolicyNames", []
            ):
                iam.delete_user_policy(UserName=user_name, PolicyName=policy)
            iam.delete_user(UserName=user_name)
        except ClientError as exc:
            if exc.response.get("Error", {}).get("Code") != "NoSuchEntity":
                raise

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
        policy_body_path = policy_path.with_suffix(".policy.json")
        policy_body_path.write_text(json.dumps(policy, indent=2), encoding="utf-8")

        if not self._client.bucket_exists(bucket):
            self._client.make_bucket(bucket)

        identity = self._identity_mode()
        if identity == "iam":
            iam_creds = self._create_iam_identity(
                deployment_id=deployment_id,
                bucket=bucket,
                prefix=prefix,
                policy=policy,
            )
            access_key = iam_creds["access_key"]
            secret_key = iam_creds["secret_key"]
            policy_name = iam_creds["policy_name"]
            user_created = True
            policy_attached = True
        else:
            if not access_key or not secret_key:
                raise ValueError("access_key and secret_key are required")
            alias = self._ensure_alias()
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
            self._mc(
                "admin", "policy", "create", alias, policy_name, str(policy_body_path)
            )
            remove = subprocess.run(
                [
                    shutil.which("mc") or "mc",
                    "admin",
                    "user",
                    "remove",
                    alias,
                    access_key,
                ],
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
            user_created = True
            policy_attached = True

        policy_path.write_text(
            json.dumps(
                {
                    "deployment_id": deployment_id,
                    "access_key": access_key,
                    "bucket": bucket,
                    "prefix": prefix,
                    "policy_name": policy_name,
                    "identity": identity,
                    "policy": policy,
                },
                indent=2,
            ),
            encoding="utf-8",
        )

        return {
            "policy_path": str(policy_path),
            "policy_name": policy_name,
            "access_key": access_key,
            "secret_key": secret_key,
            "bucket": bucket,
            "prefix": prefix,
            "identity": identity,
            "user_created": user_created,
            "policy_attached": policy_attached,
        }

    def export_prefix(self, *, bucket: str, prefix: str, dest_dir: Path) -> int:
        prefix = prefix.strip("/")
        dest_dir.mkdir(parents=True, exist_ok=True)
        count = 0
        for obj in self._client.list_objects(bucket, prefix=f"{prefix}/", recursive=True):
            if obj.is_dir:
                continue
            relative = obj.object_name[len(prefix) :].lstrip("/")
            if not relative:
                continue
            target = dest_dir / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            self._client.fget_object(bucket, obj.object_name, str(target))
            count += 1
        return count

    def import_prefix(self, *, bucket: str, prefix: str, source_dir: Path) -> int:
        prefix = prefix.strip("/")
        if not self._client.bucket_exists(bucket):
            self._client.make_bucket(bucket)
        if not source_dir.is_dir():
            return 0
        count = 0
        for path in source_dir.rglob("*"):
            if not path.is_file():
                continue
            relative = path.relative_to(source_dir).as_posix()
            object_name = f"{prefix}/{relative}"
            self._client.fput_object(bucket, object_name, str(path))
            count += 1
        return count

    def delete_tenant(
        self,
        *,
        deployment_id: str,
        access_key: str,
        bucket: str,
        prefix: str,
    ) -> dict:
        prefix = prefix.strip("/")
        policy_name = f"dilibori-{deployment_id[:24]}"
        deleted_objects = 0
        if prefix:
            for obj in self._client.list_objects(
                bucket, prefix=f"{prefix}/", recursive=True
            ):
                if obj.is_dir:
                    continue
                self._client.remove_object(bucket, obj.object_name)
                deleted_objects += 1

        if self._identity_mode() == "iam":
            self._delete_iam_identity(deployment_id=deployment_id)
        else:
            alias = self._ensure_alias()
            mc = shutil.which("mc") or "mc"
            subprocess.run(
                [mc, "admin", "user", "remove", alias, access_key],
                capture_output=True,
                text=True,
                check=False,
            )
            subprocess.run(
                [mc, "admin", "policy", "remove", alias, policy_name],
                capture_output=True,
                text=True,
                check=False,
            )

        policy_path = (
            Path(self._settings.data_dir) / "minio-policies" / f"{deployment_id}.json"
        )
        policy_path.unlink(missing_ok=True)
        policy_path.with_suffix(".policy.json").unlink(missing_ok=True)

        return {
            "access_key": access_key,
            "deleted_objects": deleted_objects,
            "policy_name": policy_name,
        }
