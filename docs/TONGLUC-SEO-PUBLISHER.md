# Tongluc Rank Math SEO Publisher (draft v1)

This fork extends JOOservices WordPress MCP without changing existing site registry, OAuth configuration, or the original 53 tools.

## New Rank Math metadata fields

The existing `wordpress_get_seo` and `wordpress_update_seo` tools now expose:

| Field | API type | WordPress postmeta | Notes |
|---|---|---|---|
| `focus_keywords` | `string[]` (0–5) | `rank_math_focus_keyword` | Ordered, first is the primary keyword; `[]` clears; no commas inside individual keywords |
| `pillar_content` | `boolean` | `rank_math_pillar_content` | Maps `true` to `on` and `false` to `off` |

Writes to these fields are **rejected** if Rank Math is not the detected SEO provider. Values are validated *before* any metadata is written to avoid partial updates. Existing title, description, canonical, Open Graph, and noindex behavior is retained. `wordpress_update_seo` still requires `confirm: true`.

The REST endpoint is unchanged: `PATCH /wp-json/chatgpt-connector/v1/seo/metadata/{post_id}`. It requires the existing `posts.update` or `pages.update` connection scope and WordPress edit permission.

## New MCP tool: `tongluc_create_seo_draft`

Creates or resumes a **draft**, saves SEO metadata, then reads the content and SEO fields back to verify persistence. This tool does **not publish**.

Required: `title`, `content`, `seo.title`, `seo.description`. Optional: `type` (`post`/`page`), `slug`, `excerpt`, `categories`, `tags`, `featured_media`, `seo.focus_keywords`, `seo.pillar_content`, and `post_id` for resuming an existing draft.

Example:

```json
{
  "site": "huthamcautancuong-com",
  "type": "post",
  "title": "5 dấu hiệu hầm cầu đầy và cách xử lý tại Quảng Ngãi",
  "content": "<p>...</p>",
  "categories": [1],
  "tags": ["hút hầm cầu"],
  "seo": {
    "title": "5 Dấu Hiệu Hầm Cầu Đầy Và Cách Xử Lý Tại Quảng Ngãi",
    "description": "Nhận biết các dấu hiệu hầm cầu đầy tại Quảng Ngãi và cách xử lý an toàn.",
    "focus_keywords": ["hầm cầu đầy", "dấu hiệu hầm cầu đầy", "hút hầm cầu Quảng Ngãi"],
    "pillar_content": true
  },
  "confirm": false
}
```

1. First call with `confirm: false` to view the proposed changes.
2. After approval, repeat with `confirm: true` to write the draft.
3. The result includes `post_id`, saved SEO metadata, `verification.passed`, and both content/SEO differences.
4. On a partial SEO failure, **do not create another post**. Retry with returned `post_id`; the tool refuses to resume published/private/scheduled content.
5. Publish separately with `wordpress_update_content({ id: post_id, status: "publish", confirm: true })` after reviewing the result.

**Important:** Creating content and writing metadata use two REST requests; the workflow is not atomic. Treat `verification.passed=false` as a blocker. Content is accepted as HTML; the tool does not parse a FULL handoff Markdown file itself.

## Image workflow

Upload or reuse an image *before* `tongluc_create_seo_draft`. Pass the verified WordPress attachment ID as `featured_media`. The MCP tool does not generate images. Image metadata (ALT/caption/description) is managed by the existing media tools.

A previous test on `huthamcautancuong.com` encountered `MEDIA_VERIFY_FAILED` / `post_validate.sha256_mismatch`. The site runs EWWW Image Optimizer; modification during upload is a **hypothesis**, not a verified root cause. **Do not disable SHA256 verification globally**. Diagnose whether the optimizer rewrites original bytes; add secure support and regression tests separately.

## Deployment and safety

This branch is **not deployed** automatically:

- Back up the current fork, `.env.prod`, OAuth storage, and installed WordPress plugin.
- Build and test both the PHP plugin and Node MCP server in a staging environment.
- Install the updated WordPress plugin first, then build/deploy the updated MCP server.
- Do not change `MCP_PUBLISH=127.0.0.1:3100:3000` in your Mac's `.env.prod`. Host port 3000 belongs to a different service.
- Run regression checks for the existing 53 tools, OAuth, media uploads and publishing confirmation.
- Refresh the connected ChatGPT plugin tool list after deployment.
- Require review and `confirm: true` before writing or publishing. Avoid granting unrelated plugin, theme, user or core-admin scopes to an SEO-only connection.

## Deferred

- Image optimizer compatibility fix and verified 900×700 featured-image test.
- A FULL handoff Markdown parser and media/keyword mapping automation.
- Direct Rank Math SEO score/Schema/advanced audit integration.
- Scheduled publishing and bulk multi-site orchestration.
