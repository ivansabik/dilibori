from __future__ import annotations


def effective_noindex(tenant_noindex: bool, environment: str) -> bool:
    """Block indexing unless this is a production deployment with noindex off.

    Missing or unknown environment fails closed (noindex).
    """
    if tenant_noindex:
        return True
    return environment.strip().lower() != "production"


def render_tenant_snippet(
    *, hostname: str, container_name: str, noindex: bool = False
) -> str:
    """Pure Caddy site-block text for one tenant hostname."""
    if noindex:
        robots_body = "User-agent: *\nDisallow: /\n"
        return (
            f"http://{hostname} {{\n"
            f'\theader X-Robots-Tag "noindex, nofollow"\n'
            f"\thandle /robots.txt {{\n"
            f'\t\theader Content-Type "text/plain; charset=utf-8"\n'
            f"\t\trespond `{robots_body}` 200\n"
            f"\t}}\n"
            f"\thandle {{\n"
            f"\t\treverse_proxy {container_name}:80\n"
            f"\t}}\n"
            f"}}\n"
        )

    return f"http://{hostname} {{\n\treverse_proxy {container_name}:80\n}}\n"
