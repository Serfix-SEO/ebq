// src/api/serfix/articles/route.ts
// Receives signed article deliveries from Serfix and stores them in the
// serfix-blog module. Authentication: X-Serfix-Signature is an HMAC-SHA256 of
// the raw request body using your SERFIX_SECRET environment variable — it
// must exactly match the signing secret configured in Serfix.
import type { MedusaRequest, MedusaResponse } from "@medusajs/framework/http"
import crypto from "crypto"
import { SERFIX_BLOG_MODULE } from "../../../modules/serfix-blog"

export const POST = async (req: MedusaRequest, res: MedusaResponse) => {
  const secret = process.env.SERFIX_SECRET ?? ""
  const signature = String(req.headers["x-serfix-signature"] ?? "")
  const raw = (req as unknown as { rawBody?: string | Buffer }).rawBody

  if (!secret || !signature || raw === undefined) {
    return res.status(401).json({ ok: false, error: "missing signature or SERFIX_SECRET" })
  }
  const expected =
    "sha256=" + crypto.createHmac("sha256", secret).update(raw).digest("hex")
  const a = Buffer.from(signature)
  const b = Buffer.from(expected)
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
    return res.status(401).json({ ok: false, error: "invalid signature" })
  }

  const payload = req.body as Record<string, any>

  // Connection test from the Serfix integrations page — nothing to store.
  if (payload.event === "verify") {
    return res.json({ ok: true })
  }

  const article = (payload.article ?? {}) as Record<string, any>
  const externalId = String(payload.external_id ?? article.slug ?? "")
  const slug = String(article.slug ?? externalId)
  if (!externalId || !slug || !article.html) {
    return res.status(422).json({ ok: false, error: "missing article fields" })
  }

  const data = {
    title: String(article.h1 ?? article.meta_title ?? slug),
    slug,
    html: String(article.html),
    meta_title: article.meta_title ? String(article.meta_title) : null,
    meta_description: article.meta_description ? String(article.meta_description) : null,
    canonical_url: article.canonical_url ? String(article.canonical_url) : null,
    og_image: article.og_image ? String(article.og_image) : null,
    language: String(article.language ?? "en"),
    tags: Array.isArray(article.secondary_keywords) ? article.secondary_keywords : [],
    status: payload.status === "draft" || payload.test === true ? "draft" : "published",
    published_at: new Date(),
  }

  const blog = req.scope.resolve(SERFIX_BLOG_MODULE) as any
  const [existing] = await blog.listSerfixPosts({ external_id: externalId })
  if (existing) {
    await blog.updateSerfixPosts({ id: existing.id, ...data })
  } else {
    await blog.createSerfixPosts({ external_id: externalId, ...data })
  }

  // When SERFIX_STOREFRONT_URL is set (e.g. https://your-store.com) Serfix
  // receives the live URL back — it powers the article's "View live" link,
  // Google indexing submission and rank tracking.
  const base = (process.env.SERFIX_STOREFRONT_URL ?? "").replace(/\/+$/, "")
  return res.json({
    ok: true,
    id: externalId,
    url: base && data.status === "published" ? `${base}/blog/${slug}` : undefined,
  })
}
