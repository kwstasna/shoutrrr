import { FileText } from 'lucide-react';

import {
    CommandGroup,
    CommandItem,
    CommandSeparator,
} from '@/components/ui/command';
import type { RecentItem } from '@/lib/command/recents';
import { show as postShow } from '@/routes/posts';

interface Post {
    id: string;
    excerpt: string;
}

interface PostsGroupProps {
    posts: Post[];
    loading: boolean;
    error: boolean;
    go: (item: RecentItem) => () => void;
}

export function PostsGroup({ posts, loading, error, go }: PostsGroupProps) {
    return (
        <>
            <CommandSeparator alwaysRender />
            <CommandGroup heading="Posts">
                {loading && posts.length === 0 && (
                    <CommandItem disabled value="posts-loading">
                        Searching…
                    </CommandItem>
                )}
                {error && (
                    <CommandItem disabled value="posts-error">
                        Couldn't load posts
                    </CommandItem>
                )}
                {!loading && !error && posts.length === 0 && (
                    <CommandItem disabled value="posts-empty">
                        No posts found
                    </CommandItem>
                )}
                {posts.map((post) => (
                    <CommandItem
                        key={post.id}
                        value={`post ${post.id} ${post.excerpt}`}
                        onSelect={go({
                            id: post.id,
                            kind: 'post',
                            label: post.excerpt,
                            href: postShow(post.id).url,
                        })}
                    >
                        <FileText className="size-4" aria-hidden />
                        <span className="truncate">{post.excerpt}</span>
                    </CommandItem>
                ))}
            </CommandGroup>
        </>
    );
}
