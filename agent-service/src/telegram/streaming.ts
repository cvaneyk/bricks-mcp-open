import type { Context } from "grammy";

const TYPING_INTERVAL_MS = 4500;

export class AgentStreamHandler {
  private ctx: Context;
  private chatId: number;
  private statusMsgId: number | null = null;
  private typingInterval: ReturnType<typeof setInterval> | null = null;
  private lastPhase = "";
  private startTime = Date.now();

  constructor(ctx: Context) {
    this.ctx = ctx;
    this.chatId = ctx.chat!.id;
  }

  async start(label: string) {
    this.startTime = Date.now();
    const msg = await this.ctx.reply(`⏳ *${label}*\nStarte...`, { parse_mode: "Markdown" });
    this.statusMsgId = msg.message_id;
    this.typingInterval = setInterval(() => {
      this.ctx.api.sendChatAction(this.chatId, "typing").catch(() => {});
    }, TYPING_INTERVAL_MS);
  }

  async updatePhase(phase: string, detail?: string) {
    this.lastPhase = phase;
    if (!this.statusMsgId) return;

    const elapsed = ((Date.now() - this.startTime) / 1000).toFixed(0);
    let text = `⏳ *Phase: ${phase}*\n⏱ ${elapsed}s`;
    if (detail) text += `\n${detail}`;

    await this.ctx.api
      .editMessageText(this.chatId, this.statusMsgId, text, { parse_mode: "Markdown" })
      .catch(() => {});
  }

  async finish(summary: string) {
    if (this.typingInterval) {
      clearInterval(this.typingInterval);
      this.typingInterval = null;
    }

    const elapsed = ((Date.now() - this.startTime) / 1000).toFixed(0);
    const text = `✅ *Fertig* (${elapsed}s)\n\n${summary}`;

    if (this.statusMsgId) {
      await this.ctx.api
        .editMessageText(this.chatId, this.statusMsgId, text, { parse_mode: "Markdown" })
        .catch(() => {});
    } else {
      await this.ctx.reply(text, { parse_mode: "Markdown" });
    }
  }

  async error(message: string) {
    if (this.typingInterval) {
      clearInterval(this.typingInterval);
      this.typingInterval = null;
    }
    await this.ctx.reply(`❌ *Fehler*\n${message}`, { parse_mode: "Markdown" });
  }

  destroy() {
    if (this.typingInterval) {
      clearInterval(this.typingInterval);
      this.typingInterval = null;
    }
  }
}
