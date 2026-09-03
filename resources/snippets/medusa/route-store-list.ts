// src/api/store/blog/route.ts
// Public list of published posts for your storefront.
// Store routes require your publishable API key header, which the Medusa
// storefront SDK adds automatically.
import type { MedusaRequest, MedusaResponse } from "@medusajs/framework/http"
import { SERFIX_BLOG_MODULE } from "../../../modules/serfix-blog"

export const GET = async (req: MedusaRequest, res: MedusaResponse) => {
  const blog = req.scope.resolve(SERFIX_BLOG_MODULE) as any
  const posts = await blog.listSerfixPosts(
    { status: "published" },
    {
      select: ["title", "slug", "meta_description", "og_image", "language", "published_at"],
      order: { published_at: "DESC" },
      take: 100,
    }
  )
  return res.json({ posts })
}
