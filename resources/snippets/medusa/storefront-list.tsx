// Storefront (Next.js starter): src/app/[countryCode]/(main)/blog/page.tsx
// Lists published articles. Uses the starter's configured SDK so the
// publishable API key is sent automatically.
import { sdk } from "@lib/config"
import Link from "next/link"

export const metadata = { title: "Blog" }

type Post = {
  title: string
  slug: string
  meta_description: string | null
  og_image: string | null
  published_at: string | null
}

export default async function BlogIndex() {
  const { posts } = await sdk.client.fetch<{ posts: Post[] }>("/store/blog", {
    cache: "no-store",
  })

  return (
    <div className="content-container py-12">
      <h1 className="text-3xl font-semibold mb-8">Blog</h1>
      <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        {posts.map((post) => (
          <Link key={post.slug} href={`/blog/${post.slug}`} className="group">
            {post.og_image && (
              <img
                src={post.og_image}
                alt={post.title}
                className="mb-3 aspect-video w-full rounded-lg object-cover"
              />
            )}
            <h2 dir="auto" className="text-lg font-medium group-hover:underline">
              {post.title}
            </h2>
            {post.meta_description && (
              <p dir="auto" className="mt-1 text-sm text-gray-600">
                {post.meta_description}
              </p>
            )}
          </Link>
        ))}
      </div>
    </div>
  )
}
