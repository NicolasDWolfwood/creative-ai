# Unraid deployment bundle

Use the same `compose.yaml` for two Compose Manager Plus stacks:

- `creative-ai-staging` with `staging.env.example` at `10.18.0.223:80` on `creanet`
- `creative-ai-production` with `production.env.example` at `10.18.0.224:80` on `creanet`

The web services have no published host ports. The reverse proxy must join `creanet` and address them directly; workers also join the external network with dynamic addresses.

Use Compose Manager Plus indirect project paths under `/mnt/user/appdata/creative-ai-deploy/`. Copy the matching example into the stack's `.env` tab, replace every placeholder, and keep the resulting file off Git. Copy `update.sh` beside the Compose file.

Routine update commands from each stack folder:

```bash
bash update.sh --yes  # staging
bash update.sh        # production; requires a digest and asks for backup confirmation
```

Read the complete setup, migration, proxy, promotion, and rollback procedure in [`UNRAID.md`](../../UNRAID.md).
