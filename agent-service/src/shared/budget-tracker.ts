interface BudgetState {
  perPageLimit: number;
  perBatchLimit: number;
  currentPageCost: number;
  totalBatchCost: number;
  pageCount: number;
}

const state: BudgetState = {
  perPageLimit: parseFloat(process.env.MAX_BUDGET_PER_PAGE || "1.50"),
  perBatchLimit: parseFloat(process.env.MAX_BUDGET_PER_BATCH || "15.00"),
  currentPageCost: 0,
  totalBatchCost: 0,
  pageCount: 0,
};

export function resetPageBudget() {
  state.currentPageCost = 0;
  state.pageCount++;
}

export function addCost(usd: number) {
  state.currentPageCost += usd;
  state.totalBatchCost += usd;
}

export function isPageBudgetExceeded(): boolean {
  return state.currentPageCost >= state.perPageLimit;
}

export function isBatchBudgetExceeded(): boolean {
  return state.totalBatchCost >= state.perBatchLimit;
}

export function getBudgetSummary() {
  return {
    currentPage: {
      spent: state.currentPageCost,
      limit: state.perPageLimit,
      remaining: state.perPageLimit - state.currentPageCost,
    },
    batch: {
      spent: state.totalBatchCost,
      limit: state.perBatchLimit,
      remaining: state.perBatchLimit - state.totalBatchCost,
      pagesBuilt: state.pageCount,
    },
  };
}

export function resetBatchBudget() {
  state.currentPageCost = 0;
  state.totalBatchCost = 0;
  state.pageCount = 0;
}
