# Facebook Messenger AI Chatbot

A PHP-based webhook for Facebook Messenger that integrates with Google's Gemini AI models. Deployed on Vercel with automatic fallback between API keys and model tiers.

## Features

- 🤖 **Google Gemini Integration** - Uses Gemini 2.5 Pro and Flash models
- 🔄 **Smart Fallback System** - Automatically switches between:
  - Main API Key → Gemini 2.5 Pro → Gemini 2.5 Flash
  - Backup API Key → Gemini 2.5 Pro → Gemini 2.5 Flash
- ⚡ **Serverless Deployment** - Runs on Vercel using PHP runtime
- 💬 **Facebook Messenger** - Responds to messages automatically

## Project Structure

```
.
├── api/
│   └── webhook.php          # Main webhook handler
├── vercel.json              # Vercel configuration
├── .env.example             # Environment variables template
├── .gitignore              # Git ignore rules
└── README.md               # This file
```

## Setup

### 1. Clone and Install

```bash
git clone <your-repo-url>
cd free-chat-bot
```

### 2. Configure Environment Variables

Copy `.env.example` to `.env.local` for local development:

```bash
cp .env.example .env.local
```

Update the following variables:

```env
FACEBOOK_PAGE_ACCESS_TOKEN=your_page_access_token
FACEBOOK_VERIFY_TOKEN=your_verify_token
GEMINI_API_KEY_MAIN=your_main_gemini_key
GEMINI_API_KEY_BACKUP=your_backup_gemini_key
```

**Where to get these:**

- **Facebook Tokens**: [Facebook Developers Console](https://developers.facebook.com/)
  - Create a Facebook App
  - Add Messenger product
  - Generate Page Access Token
  - Create a custom Verify Token (any random string)
  
- **Gemini API Keys**: [Google AI Studio](https://makersuite.google.com/app/apikey)
  - Create two API keys for redundancy
  - One for main, one for backup

### 3. Deploy to Vercel

```bash
# Install Vercel CLI (if not already installed)
npm i -g vercel

# Deploy
vercel

# Add environment variables in Vercel dashboard
# Project Settings → Environment Variables
```

### 4. Configure Facebook Webhook

1. Go to Facebook App Dashboard → Messenger → Settings
2. In **Webhooks** section, click "Add Callback URL"
3. **Callback URL**: `https://your-vercel-project.vercel.app/api/webhook`
4. **Verify Token**: The same token you set in `FACEBOOK_VERIFY_TOKEN`
5. Subscribe to `messages` webhook events

## How It Works

### Fallback Logic

The bot intelligently handles rate limits and failures:

1. **Try Main Key with Pro model** (`GEMINI_API_KEY_MAIN` + `gemini-2.5-pro`)
2. If rate limited → **Try Main Key with Flash model** (`GEMINI_API_KEY_MAIN` + `gemini-2.5-flash`)
3. If still failing → **Try Backup Key with Pro model** (`GEMINI_API_KEY_BACKUP` + `gemini-2.5-pro`)
4. If rate limited → **Try Backup Key with Flash model** (`GEMINI_API_KEY_BACKUP` + `gemini-2.5-flash`)
5. If all exhausted → Return error message

### Webhook Flow

```
Facebook Messenger → Vercel → webhook.php → Gemini API → Response → Facebook Messenger
```

## Development

To test locally using Vercel CLI:

```bash
vercel dev
```

This will start a local server at `http://localhost:3000`.

To expose your local webhook to Facebook for testing, use a tunneling service like [ngrok](https://ngrok.com/):

```bash
ngrok http 3000
```

## API Reference

### Gemini Models Used

- **gemini-2.5-pro**: Higher quality, more capable, slower
- **gemini-2.5-flash**: Faster responses, optimized for speed

### Facebook Messenger API

- **Graph API Version**: v21.0
- **Endpoint**: `https://graph.facebook.com/v21.0/me/messages`

## Troubleshooting

**Webhook verification fails:**
- Ensure `FACEBOOK_VERIFY_TOKEN` matches between code and Facebook settings
- Check Vercel deployment logs for errors

**No AI responses:**
- Verify Gemini API keys are valid
- Check Vercel logs: `vercel logs`
- Ensure Facebook Page Access Token has correct permissions

**Rate limit errors:**
- The fallback system should handle this automatically
- Check if both API keys are properly configured

## License

MIT
