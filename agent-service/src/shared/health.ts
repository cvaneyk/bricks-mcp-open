import { createServer } from "http";

const startedAt = new Date().toISOString();
let lastActivity = startedAt;
let currentTask = "idle";

export function setActivity(task: string) {
  lastActivity = new Date().toISOString();
  currentTask = task;
}

export function startHealthServer(port: number) {
  createServer((req, res) => {
    if (req.url === "/health") {
      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify({
        status: "ok",
        startedAt,
        lastActivity,
        currentTask,
        uptime: process.uptime(),
      }));
    } else {
      res.writeHead(404);
      res.end("Not Found");
    }
  }).listen(port, () => {
    console.log(`Health endpoint listening on :${port}/health`);
  });
}
