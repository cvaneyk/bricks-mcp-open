import { Bot, webhookCallback, type Context } from "grammy";

const ALLOWED_IDS = (process.env.TELEGRAM_ALLOWED_IDS || process.env.TELEGRAM_CHAT_ID || "")
  .split(",")
  .map((id) => parseInt(id.trim(), 10))
  .filter(Boolean);

export function createBot(token: string) {
  const bot = new Bot(token);

  bot.use(async (ctx, next) => {
    const userId = ctx.from?.id;
    if (!userId || !ALLOWED_IDS.includes(userId)) {
      await ctx.reply("⛔ Nicht autorisiert.");
      return;
    }
    await next();
  });

  return bot;
}

export function isAuthorized(ctx: Context): boolean {
  const userId = ctx.from?.id;
  return !!userId && ALLOWED_IDS.includes(userId);
}

export async function setupWebhook(bot: Bot, url: string) {
  await bot.api.setWebhook(url);
  console.log(`Telegram webhook set to ${url}`);
}

export function getWebhookHandler(bot: Bot) {
  return webhookCallback(bot, "std/http");
}
