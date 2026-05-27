import { existsSync, writeFileSync, unlinkSync, readFileSync } from "fs";
import { join } from "path";

const DATA_DIR = process.env.DATA_DIR || "/data";
const LOCK_FILE = join(DATA_DIR, "overnight-build.lock");

interface LockInfo {
  startedAt: string;
  currentPage: number;
  totalPages: number;
  currentIndustry: string;
}

export function acquireLock(info: LockInfo): boolean {
  if (isLocked()) return false;
  writeFileSync(LOCK_FILE, JSON.stringify(info, null, 2));
  return true;
}

export function updateLock(info: Partial<LockInfo>) {
  if (!isLocked()) return;
  const current = getLockInfo();
  if (current) {
    writeFileSync(LOCK_FILE, JSON.stringify({ ...current, ...info }, null, 2));
  }
}

export function releaseLock() {
  if (existsSync(LOCK_FILE)) unlinkSync(LOCK_FILE);
}

export function isLocked(): boolean {
  return existsSync(LOCK_FILE);
}

export function getLockInfo(): LockInfo | null {
  if (!existsSync(LOCK_FILE)) return null;
  try {
    return JSON.parse(readFileSync(LOCK_FILE, "utf-8"));
  } catch {
    return null;
  }
}
