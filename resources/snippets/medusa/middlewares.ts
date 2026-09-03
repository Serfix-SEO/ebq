// src/api/middlewares.ts
// If this file already exists in your project, add ONLY the route entry below
// to your existing `routes` array. preserveRawBody keeps the exact request
// bytes available so the delivery signature can be verified.
import { defineMiddlewares } from "@medusajs/framework/http"

export default defineMiddlewares({
  routes: [
    {
      matcher: "/serfix/articles",
      method: ["POST"],
      bodyParser: { preserveRawBody: true },
    },
  ],
})
