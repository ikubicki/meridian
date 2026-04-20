import { formatDate, AvatarCircle } from './TopicView.jsx';
import '../styles/PostItem.css';

export default function PostItem({ post }) {
  return (
    <div className="bubble bubble-reply" id={`p${post.post_id}`}>

      {/* ── Author row ── */}
      <div className="bubble-author">
        <AvatarCircle name={post.poster_name} colour={post.poster_colour} size={34} />
        <div className="bubble-author-info">
          <a href={`#user-${post.poster_id}`} className="bubble-username">
            {post.poster_colour
              ? <span style={{ color: `#${post.poster_colour}` }}>{post.poster_name}</span>
              : post.poster_name}
          </a>
          <span className="bubble-date">{formatDate(post.post_time)}</span>
        </div>
      </div>

      {/* ── Content ── */}
      <div className="bubble-text">{post.post_text}</div>

      {post.post_edit_count > 0 && post.post_edit_time && (
        <div className="bubble-edited">
          <span className="material-symbols-outlined icon-inline">edit</span>
          {' '}edytowano {formatDate(post.post_edit_time)}
        </div>
      )}

      <div className="bubble-footer">
        <a href="#reply" className="btn-post-action">
          <span className="material-symbols-outlined icon-inline">reply</span> Cytuj
        </a>
      </div>

    </div>
  );
}
