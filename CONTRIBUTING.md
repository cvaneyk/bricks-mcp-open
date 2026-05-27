# Contributing to bricks-mcp

Thanks for your interest in contributing! Here's how you can help.

## Getting Started

1. Fork the repository
2. Clone your fork locally
3. Install dependencies: `npm install`
4. Set up a WordPress site with Bricks Builder and the included plugin
5. Configure your `.env` file

## Development

```bash
# Start the server
npm start

# Test with MCP Inspector
npm run inspect
```

## Pull Requests

- Keep PRs focused on a single change
- Include a clear description of what changed and why
- Test your changes against a real WordPress + Bricks Builder installation
- Follow the existing code style

## Reporting Issues

- Include your Node.js version, WordPress version, and Bricks Builder version
- Describe the expected vs. actual behavior
- Include relevant error messages from stderr

## Code Style

- ES Modules (`import`/`export`)
- Async/await for all async operations
- Descriptive tool names prefixed with `bricks_`
- Error responses use `{ content: [...], isError: true }`

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
