// src/modules/serfix-blog/index.ts
import { Module } from "@medusajs/framework/utils"
import SerfixBlogModuleService from "./service"

export const SERFIX_BLOG_MODULE = "serfix_blog"

export default Module(SERFIX_BLOG_MODULE, {
  service: SerfixBlogModuleService,
})
