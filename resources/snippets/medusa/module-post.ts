// src/modules/serfix-blog/models/post.ts
// Blog post storage for articles delivered by Serfix.
import { model } from "@medusajs/framework/utils"

export const SerfixPost = model.define("serfix_post", {
  id: model.id().primaryKey(),
  // Serfix's stable article id — updates to an article reuse it.
  external_id: model.text().unique(),
  title: model.text(),
  slug: model.text().unique(),
  // Full article body as ready-to-render HTML (images are absolute URLs).
  html: model.text(),
  meta_title: model.text().nullable(),
  meta_description: model.text().nullable(),
  canonical_url: model.text().nullable(),
  og_image: model.text().nullable(),
  language: model.text().default("en"),
  tags: model.json().nullable(),
  status: model.text().default("published"), // published | draft
  published_at: model.dateTime().nullable(),
})
