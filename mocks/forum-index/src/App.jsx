import { useState, useEffect, useRef } from 'react';
import './styles/Header.css';
import './styles/StickyHeader.css';
import './styles/ActionBar.css';
import { forums, stats, onlineUsers, birthdays, topic, posts, FORUM_TYPE_CAT, FORUM_TYPE_POST, FORUM_TYPE_LINK } from './data.js';
import ForumList from './components/ForumList.jsx';
import StatBlocks from './components/StatBlocks.jsx';
import TopicView from './components/TopicView.jsx';

export default function App() {
  const [view, setView] = useState('index'); // 'index' | 'topic'

  return (
    <div id="wrap" className="wrap">
      <a href="#page-body" className="skip-link">Skip to content</a>
      <Header />
      <StickyHeader view={view} topic={topic} onBack={() => setView('index')} />
      <main id="page-body">
        <div className="page-body-inner">
          {view === 'index' ? (
            <>
              <ActionBar />
              <ForumList forums={forums} onTopicClick={() => setView('topic')} />
              <StatBlocks stats={stats} onlineUsers={onlineUsers} birthdays={birthdays} />
            </>
          ) : (
            <TopicView topic={topic} posts={posts} onBack={() => setView('index')} />
          )}
        </div>
      </main>
      <Footer />
    </div>
  );
}

/* ── Header ─────────────────────────────────────────────── */
function Header() {
  return (
    <div id="page-header">
      <div className="headerbar" role="banner">
        <div className="inner headerbar-inner">
          {/* Left: logo */}
          <div className="header-logo">
            <a href="#" title="Board index">
              <img
                className="site-logo"
                src="./site_logo.svg"
                alt="phpBB"
              />
            </a>
          </div>

          {/* Center: search */}
          <div className="header-search" role="search">
            <form onSubmit={(e) => e.preventDefault()}>
              <fieldset>
                <input
                  name="keywords"
                  type="search"
                  maxLength={128}
                  className="inputbox search"
                  placeholder="Search…"
                  aria-label="Search the forum"
                />
                <button className="button button-search" type="submit" title="Search">
                  <span className="material-symbols-outlined">search</span>
                </button>
              </fieldset>
            </form>
          </div>

          {/* Right: hamburger menu */}
          <HamburgerMenu />
        </div>
      </div>
    </div>
  );
}

/* ── Hamburger menu (keyboard-accessible) ───────────────── */
function HamburgerMenu() {
  const [open, setOpen] = useState(false);
  const menuRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    const handleKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    const handleClickOutside = (e) => {
      if (menuRef.current && !menuRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('keydown', handleKey);
    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('keydown', handleKey);
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [open]);

  return (
    <div className="header-hamburger" ref={menuRef}>
      <button
        className="hamburger-btn"
        aria-label="Menu"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <span className="material-symbols-outlined">menu</span>
      </button>
      <div className={`dropdown dropdown-right${open ? ' dropdown-open' : ''}`}>
        <ul className="dropdown-contents">
          <li><a href="#"><span className="material-symbols-outlined menu-icon">help</span> FAQ</a></li>
          <li role="separator" aria-hidden="true" className="separator" />
          <li><a href="#"><span className="material-symbols-outlined menu-icon">mark_chat_unread</span> Unanswered topics</a></li>
          <li><a href="#"><span className="material-symbols-outlined menu-icon">local_fire_department</span> Active topics</a></li>
          <li><a href="#"><span className="material-symbols-outlined menu-icon">groups</span> The team</a></li>
          <li><a href="#"><span className="material-symbols-outlined menu-icon">people</span> Members</a></li>
          <li role="separator" aria-hidden="true" className="separator" />
          <li><a href="#"><span className="material-symbols-outlined menu-icon">person_add</span> Register</a></li>
          <li><a href="#"><span className="material-symbols-outlined menu-icon">login</span> Login</a></li>
        </ul>
      </div>
    </div>
  );
}

/* ── Sticky Header (appears after 100px scroll) ─────────── */
function StickyHeader({ view, topic, onBack }) {
  const [visible, setVisible] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);

  useEffect(() => {
    const onScroll = () => setVisible(window.scrollY > 100);
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <div className={`sticky-header${visible ? ' sticky-visible' : ''}`}>
      <div className="sticky-inner">
        <div className="sticky-left">
          <a href="#" className="sticky-logo" title="Board index">
            <img src="/favicon.ico" alt="phpBB" className="sticky-ico" />
            <img src="./site_logo.svg" alt="phpBB" className="sticky-svg" />
          </a>
          <nav aria-label="Breadcrumb">
            <ul className="breadcrumbs">
              {view === 'topic' ? (
                <>
                  <li className="crumb">
                    <a href="#" onClick={(e) => { e.preventDefault(); onBack(); }}>Board index</a>
                    <span className="crumb-sep" aria-hidden="true"> › </span>
                  </li>
                  <li className="crumb">
                    <a href="#" onClick={(e) => { e.preventDefault(); onBack(); }}>{topic.forum_name}</a>
                    <span className="crumb-sep" aria-hidden="true"> › </span>
                  </li>
                  <li className="crumb current" aria-current="page">{topic.topic_title}</li>
                </>
              ) : (
                <li className="crumb">
                  <a href="#" aria-current="page">Board index</a>
                </li>
              )}
            </ul>
          </nav>
        </div>

        <div className="sticky-right">
          <button
            className="sticky-search-btn"
            onClick={() => setSearchOpen((v) => !v)}
            aria-label="Search"
          >
            <span className="material-symbols-outlined">search</span>
          </button>
          <HamburgerMenu />
        </div>
      </div>

      {searchOpen && (
        <div className="sticky-search-bar">
          <form onSubmit={(e) => e.preventDefault()}>
            <input
              name="keywords"
              type="search"
              maxLength={128}
              className="inputbox search"
              placeholder="Search…"
              aria-label="Search the forum"
              autoFocus
            />
          </form>
        </div>
      )}
    </div>
  );
}

/* ── Action bar (breadcrumb + mark forums read) ────────── */
function ActionBar() {
  return (
    <div className="action-bar compact">
      <nav aria-label="Breadcrumb">
        <ul className="breadcrumbs">
          <li className="crumb"><a href="#" aria-current="page">Board index</a></li>
        </ul>
      </nav>
      <a href="#" className="mark-read">Mark forums read</a>
    </div>
  );
}

/* ── Footer ─────────────────────────────────────────────── */
function Footer() {
  return (
    <footer id="page-footer">
      <div className="footer-info">
        <span>Powered by <a href="#">phpBB</a>® Forum Software © phpBB Limited</span>
        <span className="footer-right">Skysilver style by Irekk</span>
      </div>
    </footer>
  );
}
