# Setting Up GitHub Secrets for GENESIS Orchestrator

## Required Secrets

You need to configure the following secrets in your GitHub repository settings:
https://github.com/Mojo-Solo/genesis-orchestrator/settings/secrets/actions

### API Keys (Critical)
1. **CLAUDE_API_KEY** - Your Anthropic Claude API key
2. **OPENAI_API_KEY** - Your OpenAI API key (used as fallback)

### Security Keys
3. **HMAC_SECRET** - For webhook signature validation (generate a strong random string)
4. **ENCRYPTION_KEY** - For data encryption (32-byte key)

### Optional (for full deployment)
5. **TEMPORAL_CLOUD_KEY** - If using Temporal Cloud
6. **MYSQL_PASSWORD** - Database password
7. **REDIS_PASSWORD** - Redis password

## How to Add Secrets via GitHub CLI

```bash
# Set Claude API Key
gh secret set CLAUDE_API_KEY --repo Mojo-Solo/genesis-orchestrator

# Set OpenAI API Key  
gh secret set OPENAI_API_KEY --repo Mojo-Solo/genesis-orchestrator

# Generate and set HMAC Secret
gh secret set HMAC_SECRET --repo Mojo-Solo/genesis-orchestrator --body="$(openssl rand -hex 32)"

# Generate and set Encryption Key
gh secret set ENCRYPTION_KEY --repo Mojo-Solo/genesis-orchestrator --body="$(openssl rand -hex 32)"
```

## How to Add Secrets via GitHub Web UI

1. Go to: https://github.com/Mojo-Solo/genesis-orchestrator/settings/secrets/actions
2. Click "New repository secret"
3. Add each secret with its name and value
4. Click "Add secret"

## Verification

After adding secrets, the GitHub Actions workflow should be able to:
- Run tests with API access
- Deploy with proper authentication
- Execute security features

## Local Development

For local development, copy `.env.example` to `.env` and fill in your values:

```bash
cp .env.example .env
# Edit .env with your values
```

## Security Notes

- Never commit `.env` files to version control
- Rotate secrets regularly
- Use different keys for development/staging/production
- Monitor GitHub audit logs for secret access

## Troubleshooting

If Actions fail due to missing secrets:
1. Check the Actions logs for specific missing secret names
2. Verify secret names match exactly (case-sensitive)
3. Ensure secrets are added to the correct repository
4. Check that Actions have permission to access secrets