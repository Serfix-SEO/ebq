// Storefront (Next.js starter): src/app/[countryCode]/(main)/blog/[slug]/page.tsx
// Renders one article. The html field is trusted content authored through
// Serfix. dir="auto" keeps right-to-left languages (e.g. Arabic) laid out
// correctly.
import { sdk } from "@lib/config"
import { notFound } from "next/navigation"

type Post = {
  title: string
  slug: string
  html: string
  meta_title: string | null
  meta_description: string | null
  canonical_url: string | null
  og_image: string | null
}

async function getPost(slug: string): Promise<Post | null> {
  try {
    const { post } = await sdk.client.fetch<{ post: Post }>(
      `/store/blog/${slug}`,
      { cache: "no-store" }
    )
    return post
  } catch {
    return null
  }
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params
  const post = await getPost(slug)
  if (!post) return {}
  return {
    title: post.meta_title ?? post.title,
    description: post.meta_description ?? undefined,
    alternates: post.canonical_url ? { canonical: post.canonical_url } : undefined,
    openGraph: post.og_image ? { images: [post.og_image] } : undefined,
  }
}

export default async function BlogPost({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params
  const post = await getPost(slug)
  if (!post) notFound()

  return (
    <article dir="auto" className="content-container prose mx-auto max-w-3xl py-12">
      <h1>{post.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: post.html }} />
    </article>
  )
}
