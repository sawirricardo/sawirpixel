{app} turns a text prompt into an image, for teams. You see what an image will cost before you generate it, are charged what it actually cost, and pay nothing if it fails.

## How it works

- Write a prompt, pick a size (square, landscape or portrait) and a quality (low, medium or high), and optionally add up to four reference image links.
- The price range is shown before you submit. Submitting sets aside the top of that range from your organization's credits; when the image finishes, you're charged what it actually cost and the rest is returned. A failed image costs nothing.
- Continue from any finished image to refine it, or branch from an earlier version.
- Organizations share credits, with roles, invitations, and optional limits on spending, image counts, quality and size.
- Finished images are available for 24 hours from their link.

## For agents and developers

- **API:** authenticate with a personal access token from your profile (`Authorization: Bearer …`). Endpoints: `GET /api/user`, `POST /api/estimates`, `GET /api/generations`, `GET /api/generations/{id}`, `POST /api/generations`, `GET /api/credits`. Responses follow JSON:API.
- **MCP:** a Model Context Protocol server at `/mcp` (streamable HTTP, same tokens and scopes) with the tools `estimate_price`, `generate_image`, `get_generation`, `list_generations` and `get_credit_balance`. See the [server card](/.well-known/mcp/server-card.json).
- **Docs:** [API reference](/docs/api), [OpenAPI description](/docs/api.json), [API catalog](/.well-known/api-catalog).

## More

- [Terms of service](/terms)
- [Privacy policy](/privacy)

> {app} is a demo built for the PromptEdit assessment. Credits are simulated and no real payments are taken.
