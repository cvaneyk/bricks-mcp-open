export interface IndustryBrief {
  industry: string;
  title: string;
  locale: string;
  description: string;
  sections: string[];
  designProfile: string;
  colorHints?: string[];
  typography?: string;
  specialInstructions?: string;
}

export interface AgentPhaseConfig {
  agentName: string;
  systemPrompt: string;
  userPrompt: string;
  tools: string[];
  maxTurns: number;
  maxBudgetUsd: number;
}

export interface PhaseResult {
  success: boolean;
  output: string;
  tokenUsage: { input: number; output: number };
  costUsd: number;
  durationMs: number;
  error?: string;
}

export interface PageBuildResult {
  pageId: number;
  industry: string;
  phases: Record<string, PhaseResult>;
  qaScore: number;
  fixIterations: number;
  totalCostUsd: number;
  totalDurationMs: number;
  snapshotId?: string;
  antiPatternsDiscovered: string[];
  effectiveFixes: string[];
  status: "success" | "failed" | "skipped";
  error?: string;
}

export interface BatchContext {
  date: string;
  pagesCompleted: PageBuildResult[];
  tonightAntiPatterns: string[];
  tonightEffectiveFixes: string[];
}

export interface BatchReport {
  date: string;
  totalPages: number;
  successCount: number;
  failedCount: number;
  skippedCount: number;
  totalCostUsd: number;
  totalDurationMs: number;
  pages: PageBuildResult[];
  newLearnings: number;
  antiPatternsDiscovered: string[];
}
