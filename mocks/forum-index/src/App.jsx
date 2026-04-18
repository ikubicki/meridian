import { useState } from 'react';
import { forums, stats, onlineUsers, birthdays, FORUM_TYPE_CAT, FORUM_TYPE_POST, FORUM_TYPE_LINK } from './data.js';
import ForumList from './components/ForumList.jsx';
import StatBlocks from './components/StatBlocks.jsx';

export default function App() {
  return (
    <div id="wrap" className="wrap">
      <Header />
      <main id="page-body">
        <div className="page-body-inner">
          <ActionBar />
          <ForumList forums={forums} />
          <StatBlocks stats={stats} onlineUsers={onlineUsers} birthdays={birthdays} />
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
      {/* ── .headerbar — blue gradient banner with logo + search ── */}
      <div className="headerbar" role="banner">
        <div className="inner headerbar-inner">
          <div id="site-description" className="site-description">
            <a id="logo" className="logo" href="#" title="Board index">
              <img
                className="site-logo"
                src="/src/assets/site_logo.svg"
                alt="phpBB"
              />
            </a>
            <p className="site-desc">A vibrant community powered by phpBB</p>
          </div>

          <div id="search-box" className="search-box search-header" role="search">
            <form onSubmit={(e) => e.preventDefault()} id="search">
              <fieldset>
                <input
                  name="keywords"
                  type="search"
                  maxLength={128}
                  className="inputbox search tiny"
                  placeholder="Search…"
                />
                <button className="button button-search" type="submit" title="Search">
                  <i className="icon fa-search" aria-hidden="true">🔍</i>
                </button>
                <a href="#" className="button button-search-end" title="Advanced search">
                  <i className="icon fa-cog" aria-hidden="true">⚙</i>
                </a>
              </fieldset>
            </form>
          </div>
        </div>
      </div>

      {/* ── .navbar — navigation links + breadcrumbs ── */}
      <div className="navbar" role="navigation">
        <div className="inner">
          {/* Top row: main nav links */}
          <ul id="nav-main" className="nav-main linklist" role="menubar">
            <QuickLinks />
            <li>
              <a href="#" title="Frequently Asked Questions" role="menuitem">
                <i className="icon fa-question-circle" aria-hidden="true">❓</i>
                <span> FAQ</span>
              </a>
            </li>
            <li className="rightside">
              <a href="#" title="Login" role="menuitem">
                <i className="icon fa-power-off" aria-hidden="true">⏻</i>
                <span> Login</span>
              </a>
            </li>
            <li className="rightside">
              <a href="#" role="menuitem">
                <i className="icon fa-pencil-square-o" aria-hidden="true">✏</i>
                <span> Register</span>
              </a>
            </li>
          </ul>

          {/* Bottom row: breadcrumbs */}
          <ul id="nav-breadcrumbs" className="nav-breadcrumbs linklist navlinks" role="menubar">
            <li className="breadcrumbs">
              <span className="crumb">
                <a href="#"><i className="icon fa-home" aria-hidden="true">🏠</i></a>
              </span>
              <span className="crumb">
                <a href="#">Board index</a>
              </span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  );
}

/* ── Quick Links dropdown ───────────────────────────────── */
function QuickLinks() {
  const [open, setOpen] = useState(false);

  return (
    <li className={`quick-links dropdown-container${open ? ' open' : ''}`}>
      <a
        href="#"
        className="dropdown-trigger"
        onClick={(e) => { e.preventDefault(); setOpen((v) => !v); }}
      >
        <i className="icon fa-bars" aria-hidden="true">☰</i>
        <span> Quick links</span>
      </a>
      {open && (
        <div className="dropdown">
          <div className="pointer"><div className="pointer-inner" /></div>
          <ul className="dropdown-contents" role="menu">
            <li><a href="#" role="menuitem">Unanswered topics</a></li>
            <li><a href="#" role="menuitem">Active topics</a></li>
            <li><a href="#" role="menuitem">Search</a></li>
            <li className="separator" />
            <li><a href="#" role="menuitem">The team</a></li>
            <li><a href="#" role="menuitem">Members</a></li>
          </ul>
        </div>
      )}
    </li>
  );
}

/* ── Action bar (mark forums read) ──────────────────────── */
function ActionBar() {
  return (
    <div className="action-bar compact">
      <a href="#" className="mark-read">Mark forums read</a>
    </div>
  );
}

/* ── Footer ─────────────────────────────────────────────── */
function Footer() {
  return (
    <footer id="page-footer">
      <div className="navbar" role="navigation">
        <div className="inner">
          <ul id="nav-breadcrumbs" className="nav-breadcrumbs linklist navlinks" role="menubar">
            <li className="breadcrumbs">
              <span className="crumb">
                <a href="#"><i className="icon fa-home" aria-hidden="true">🏠</i></a>
              </span>
              <span className="crumb">
                <a href="#">Board index</a>
              </span>
            </li>
          </ul>
        </div>
      </div>
      <div className="footer-info">
        <span>Powered by <a href="#">phpBB</a>® Forum Software © phpBB Limited</span>
        <span className="footer-right">Style by Irekk</span>
      </div>
    </footer>
  );
}
