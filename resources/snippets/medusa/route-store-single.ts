// src/api/store/blog/[slug]/route.ts
// Public read of one published post by slug.
import type { MedusaRequest, MedusaResponse } from "@medusajs/framework/http"
import { SERFIX_BLOG_MODULE } from "../../../../modules/serfix-blog"

export const GET = async (req: MedusaRequest, res: MedusaResponse) => {
  const blog = req.scope.resolve(SERFIX_BLOG_MODULE) as any
  const [post] = await blog.listSerfixPosts({
    slug: String(req.params.slug ?? ""),
    status: "published",
  })
  if (!post) {
    return res.status(404).json({ ok: false, error: "not found" })
  }
  return res.json({ post })
}
