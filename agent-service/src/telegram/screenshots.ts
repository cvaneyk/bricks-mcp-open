import type { Context } from "grammy";
import { InputFile } from "grammy";

export async function sendScreenshot(
  ctx: Context,
  base64Data: string,
  caption: string
) {
  const buffer = Buffer.from(base64Data, "base64");
  await ctx.replyWithPhoto(new InputFile(buffer, "screenshot.png"), {
    caption: caption.slice(0, 1024),
  });
}

export async function sendScreenshotFromUrl(
  ctx: Context,
  url: string,
  caption: string
) {
  await ctx.replyWithPhoto(url, {
    caption: caption.slice(0, 1024),
  });
}

export function extractScreenshotsFromOutput(output: string): string[] {
  const screenshots: string[] = [];
  const pattern = /screenshot_base64["'\s:]+["']?([A-Za-z0-9+/=]{100,})/g;
  let match;
  while ((match = pattern.exec(output)) !== null) {
    screenshots.push(match[1]);
  }
  return screenshots;
}
