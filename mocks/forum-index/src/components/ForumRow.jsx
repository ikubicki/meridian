import { FORUM_TYPE_LINK } from '../data.js';

/**
 * Single forum row – mirrors the <!-- IF not forumrow.S_IS_CAT --> block
 * in forumlist_body.html.
 *
 * Layout: icon | name + desc + mods + subforums | topics | posts | last post
 */
export default function ForumRow({ forum, onTopicClick }) {
  const isLink = forum.forum_type === FORUM_TYPE_LINK;
  const hasLastPost = !!(forum.forum_last_post_subject || forum.forum_last_post_time);
  const unread = hasLastPost;

  return (
    <li className="row">
      <dl className={`row-item ${unread ? 'forum-unread' : 'forum-read'}`}>
        <dt>
          <div className="list-inner">
            <ForumIcon isLink={isLink} unread={unread} />
            <a
              href={isLink ? forum.forum_link : `#forum-${forum.forum_id}`}
              className="forumtitle"
              {...(isLink ? { target: '_blank', rel: 'noopener' } : {})}
            >
              {forum.forum_name}
            </a>
            {isLink && <span className="link-indicator" title="External link"> <span className="material-symbols-outlined" style={{fontSize: '0.9em', verticalAlign: 'middle'}}>open_in_new</span></span>}
            {forum.forum_desc && <br />}
            {forum.forum_desc && <span className="forum-desc">{forum.forum_desc}</span>}

            {/* Moderators */}
            {forum.moderators && forum.moderators.length > 0 && (
              <>
                <br />
                <strong>{forum.moderators.length === 1 ? 'Moderator:' : 'Moderators:'} </strong>
                {forum.moderators.map((m, i) => (
                  <span key={m}>
                    <a href={`#user-${m}`} className="username">{m}</a>
                    {i < forum.moderators.length - 1 && ', '}
                  </span>
                ))}
              </>
            )}

            {/* Subforums */}
            {forum.subforums && forum.subforums.length > 0 && forum.display_subforum_list === 1 && (
              <>
                <br />
                <strong>{forum.subforums.length === 1 ? 'Subforum:' : 'Subforums:'} </strong>
                {forum.subforums.map((sf, i) => (
                  <span key={sf.forum_id}>
                    <a
                      href={`#forum-${sf.forum_id}`}
                      className={`subforum ${sf.unread ? 'unread' : 'read'}`}
                      title={sf.unread ? 'Unread posts' : 'No unread posts'}
                    >
                      <span className={`subforum-icon ${sf.unread ? 'icon-unread' : 'icon-read'}`}>
                        <span className="material-symbols-outlined">{sf.is_link ? 'open_in_new' : 'forum'}</span>
                      </span>
                      {sf.forum_name}
                    </a>
                    {i < forum.subforums.length - 1 && ', '}
                  </span>
                ))}
              </>
            )}
          </div>
        </dt>

        {/* Stats columns — differ for link vs. normal forum */}
        {isLink ? (
          <dd className="redirect">
            <span>Redirects: {forum.clicks || 0}</span>
          </dd>
        ) : (
          <>
            <dd className="topics">{forum.forum_topics_approved.toLocaleString()}</dd>
            <dd className="posts">{forum.forum_posts_approved.toLocaleString()}</dd>
            <dd className="lastpost">
              {hasLastPost ? (
                <LastPostInfo forum={forum} onTopicClick={onTopicClick} />
              ) : (
                <span className="no-posts">No posts</span>
              )}
            </dd>
          </>
        )}
      </dl>
    </li>
  );
}

/* ── Forum status icon ─────────────────────────────────── */
function ForumIcon({ isLink, unread }) {
  if (isLink) {
    return (
      <span className="forum-icon forum-icon-link" title="Forum link" aria-hidden="true">
        <span className="material-symbols-outlined">link</span>
      </span>
    );
  }
  return (
    <img
      className={`forum-icon ${unread ? 'forum-icon-unread' : 'forum-icon-read'}`}
      src="./images/forum_read.gif"
      alt={unread ? 'Unread posts' : 'No unread posts'}
      title={unread ? 'Unread posts' : 'No unread posts'}
    />
  );
}

/* ── Last post snippet ──────────────────────────────────── */
function LastPostInfo({ forum, onTopicClick }) {
  const ts = forum.forum_last_post_time;
  const date = ts ? new Date(ts * 1000).toLocaleDateString('en-GB', {
    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  }) : '';
  const posterInitial = (forum.forum_last_poster_name || '?').charAt(0).toUpperCase();
  const avatarBg = forum.forum_last_poster_colour
    ? `#${forum.forum_last_poster_colour}`
    : stringToColour(forum.forum_last_poster_name || '');

  const posterStyle = forum.forum_last_poster_colour
    ? { color: `#${forum.forum_last_poster_colour}`, fontWeight: 'bold' }
    : {};

  return (
    <span className="last-post-info">
      <span className="last-post-avatar" style={{ background: avatarBg }} aria-hidden="true">{posterInitial}</span>
      <span className="last-post-content">
        <a href="#" onClick={(e) => { e.preventDefault(); onTopicClick && onTopicClick(); }} className="lastsubject" aria-label={forum.forum_last_post_subject}>
          {truncate(forum.forum_last_post_subject, 26)}
        </a>
        <br />
        by{' '}
        <a href={`#user-${forum.forum_last_poster_name}`} className="username" style={posterStyle}>
          {forum.forum_last_poster_name}
        </a>{' '}
        <a href={`#post-${forum.forum_last_post_id}`} title="View latest post" className="last-post-link"><span className="material-symbols-outlined">chevron_right</span></a>
        <br />
        <time dateTime={new Date(ts * 1000).toISOString()}>{date}</time>
      </span>
    </span>
  );
}

function truncate(str, len) {
  return str.length > len ? str.slice(0, len) + '…' : str;
}

function stringToColour(str = '') {
  let hash = 0;
  for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
  const hue = Math.abs(hash) % 360;
  return `hsl(${hue}, 45%, 52%)`;
}
