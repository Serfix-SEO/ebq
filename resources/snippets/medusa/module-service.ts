// src/modules/serfix-blog/service.ts
import { MedusaService } from "@medusajs/framework/utils"
import { SerfixPost } from "./models/post"

// MedusaService generates listSerfixPosts / createSerfixPosts /
// updateSerfixPosts / deleteSerfixPosts for the model automatically.
class SerfixBlogModuleService extends MedusaService({ SerfixPost }) {}

export default SerfixBlogModuleService
