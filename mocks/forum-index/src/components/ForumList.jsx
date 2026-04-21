import { useState } from 'react';
import { FORUM_TYPE_CAT, FORUM_TYPE_POST, FORUM_TYPE_LINK } from '../data.js';
import '../styles/ForumList.css';
import ForumRow from './ForumRow.jsx';

/**
 * Renders the forum list grouped by category, mirroring forumlist_body.html.
 *
 * phpBB layout: each category gets a `.forabg` wrapper, containing a header row
 * with column labels (Topics / Posts / Last Post) and then a list of forum rows.
 */
export default function ForumList({ forums, onTopicClick }) {
  const [collapsedCategories, setCollapsedCategories] = useState(() => new Set());

  // Group forums: categories are top-level, forums are children
  const categories = forums.filter((f) => f.forum_type === FORUM_TYPE_CAT);

  const toggleCategory = (categoryId) => {
    setCollapsedCategories((prev) => {
      const next = new Set(prev);
      if (next.has(categoryId)) {
        next.delete(categoryId);
      } else {
        next.add(categoryId);
      }
      return next;
    });
  };

  return (
    <>
      {categories.map((cat) => {
        const children = forums.filter((f) => f.parent_id === cat.forum_id);
        const isCollapsed = collapsedCategories.has(cat.forum_id);

        return (
          <div className="forabg" key={cat.forum_id} role="region" aria-label={cat.forum_name}>
            <div className="inner">
              {/* Category header row */}
              <ul className="topiclist">
                <li className="header">
                  <dl className="row-item">
                    <dt>
                      <div className="list-inner">
                        <button
                          type="button"
                          className="category-toggle"
                          onClick={() => toggleCategory(cat.forum_id)}
                          aria-expanded={!isCollapsed}
                          aria-controls={`cat-list-${cat.forum_id}`}
                          title={isCollapsed ? 'Rozwin sekcje' : 'Zwin sekcje'}
                        >
                          <span className="material-symbols-outlined" aria-hidden="true">
                            {isCollapsed ? 'chevron_right' : 'expand_more'}
                          </span>
                        </button>
                        <a href={`#cat-${cat.forum_id}`} className="category-title">
                          {cat.forum_name}
                        </a>
                      </div>
                    </dt>
                    <dd className="topics" aria-hidden="true">Topics</dd>
                    <dd className="posts" aria-hidden="true">Posts</dd>
                    <dd className="lastpost" aria-hidden="true"><span>Last post</span></dd>
                  </dl>
                </li>
              </ul>

              {/* Forum rows */}
              {!isCollapsed && (
                <ul
                  className="topiclist forums"
                  id={`cat-list-${cat.forum_id}`}
                  aria-label={`Forums in ${cat.forum_name}`}
                >
                  {children.map((forum) => (
                    <ForumRow key={forum.forum_id} forum={forum} onTopicClick={onTopicClick} />
                  ))}
                </ul>
              )}
            </div>
          </div>
        );
      })}
    </>
  );
}
