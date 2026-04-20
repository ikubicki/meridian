import PostItem from './PostItem.jsx';
import '../styles/TopicView.css';

export default function TopicView({ topic, posts, onBack }) {
  const firstPost = posts[0];
  const replies = posts.slice(1);
  const postCount = topic.topic_posts_approved;
  const pageCount = 1;

  return (
    <div className="topic-view">

      {/* ── Breadcrumb bar ── */}
      <div className="action-bar compact">
        <nav aria-label="Breadcrumb">
          <ul className="breadcrumbs">
            <li className="crumb">
              <a href="#" onClick={(e) => { e.preventDefault(); onBack(); }}>Board index</a>
              <span className="crumb-sep" aria-hidden="true"> › </span>
            </li>
            <li className="crumb">
              <a href="#" onClick={(e) => { e.preventDefault(); onBack(); }}>{topic.forum_name}</a>
              <span className="crumb-sep" aria-hidden="true"> › </span>
            </li>
            <li className="crumb current" aria-current="page">{topic.topic_title}</li>
          </ul>
        </nav>
      </div>

      {/* ── Topic hero header ── */}
      <div className="topic-hero">
        <div className="topic-hero-top">
          {firstPost && (
            <AvatarCircle name={firstPost.poster_name} colour={firstPost.poster_colour} size={52} />
          )}
          <div className="topic-hero-body">
            <h1 className="topic-hero-title">{topic.topic_title}</h1>
            {firstPost && (
              <div className="topic-hero-meta">
                <a href={`#user-${firstPost.poster_id}`} className="topic-hero-author">
                  {firstPost.poster_name}
                </a>
                <span className="topic-hero-date">
                  {formatDate(firstPost.post_time)}
                </span>
                <span className="topic-hero-count">· {postCount} {postCount === 1 ? 'post' : 'posty/postów'}</span>
              </div>
            )}
          </div>
          <a href="#reply" className="topic-reply-btn topic-reply-btn--hero">
            <span className="material-symbols-outlined icon-inline">reply</span> Odpowiedz
          </a>
        </div>
      </div>

      {/* ── First post bubble ── */}
      {firstPost && (
        <div className="bubble bubble-first" id={`p${firstPost.post_id}`}>
          <div className="bubble-text">{firstPost.post_text}</div>
          {firstPost.post_edit_count > 0 && firstPost.post_edit_time && (
            <div className="bubble-edited">
              <span className="material-symbols-outlined icon-inline">edit</span>
              {' '}edytowano {formatDate(firstPost.post_edit_time)}
            </div>
          )}
          <div className="bubble-footer">
            <a href="#reply" className="btn-post-action">
              <span className="material-symbols-outlined icon-inline">reply</span> Cytuj
            </a>
          </div>
        </div>
      )}

      {/* ── Replies ── */}
      {replies.length > 0 && (
        <ol className="reply-list" aria-label="Odpowiedzi">
          {replies.map((post) => (
            <li key={post.post_id}>
              <PostItem post={post} />
            </li>
          ))}
        </ol>
      )}

      {/* ── Bottom bar ── */}
      <div className="action-bar compact action-bar-bottom">
        <a href="#reply" className="button topic-reply-btn">
          <span className="material-symbols-outlined icon-inline">reply</span> Odpowiedz
        </a>
        <a href="#" className="return-link" onClick={(e) => { e.preventDefault(); onBack(); }}>
          &#8617; Wróć do „{topic.forum_name}"
        </a>
      </div>

    </div>
  );
}

/* ── Shared helpers ────────────────────────────────────── */
export function formatDate(ts) {
  return new Date(ts * 1000).toLocaleDateString('pl-PL', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

export function AvatarCircle({ name, colour, size = 32 }) {
  const initials = name ? name[0].toUpperCase() : '?';
  const bg = colour ? `#${colour}` : stringToColour(name);
  return (
    <span
      className="avatar-circle"
      style={{ width: size, height: size, background: bg, fontSize: size * 0.44 }}
      aria-label={name}
    >
      {initials}
    </span>
  );
}

function stringToColour(str = '') {
  let hash = 0;
  for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
  const hue = Math.abs(hash) % 360;
  return `hsl(${hue}, 45%, 52%)`;
}
