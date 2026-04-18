/**
 * Bottom stat blocks mirroring index_body.html:
 *   - Who is online
 *   - Birthdays
 *   - Statistics
 */
export default function StatBlocks({ stats, onlineUsers, birthdays }) {
  return (
    <div className="stat-blocks">
      {/* Who is online */}
      <div className="stat-block online-list">
        <h3><a href="#viewonline">Who is online</a></h3>
        <p>
          {onlineUsers.total_online}
          <br />
          {onlineUsers.record_users && <>Record: {onlineUsers.record_users}<br /></>}
          {onlineUsers.users.length > 0 && (
            <>
              <br />
              Registered users:{' '}
              {onlineUsers.users.map((u, i) => (
                <span key={u.name}>
                  <a
                    href={`#user-${u.name}`}
                    className="username"
                    style={u.colour ? { color: `#${u.colour}`, fontWeight: 'bold' } : {}}
                  >
                    {u.name}
                  </a>
                  {i < onlineUsers.users.length - 1 && ', '}
                </span>
              ))}
            </>
          )}
          {onlineUsers.legend.length > 0 && (
            <>
              <br />
              <em>
                Legend:{' '}
                {onlineUsers.legend.map((g, i) => (
                  <span key={g.name}>
                    <a
                      href="#"
                      className="username"
                      style={g.colour ? { color: `#${g.colour}`, fontWeight: 'bold' } : {}}
                    >
                      {g.name}
                    </a>
                    {i < onlineUsers.legend.length - 1 && ', '}
                  </span>
                ))}
              </em>
            </>
          )}
        </p>
      </div>

      {/* Birthdays */}
      {birthdays && birthdays.length > 0 && (
        <div className="stat-block birthday-list">
          <h3>Birthdays</h3>
          <p>
            Congratulations to:{' '}
            <strong>
              {birthdays.map((b, i) => (
                <span key={b.name}>
                  <a href={`#user-${b.name}`}>{b.name}</a>
                  {b.age !== undefined && <> ({b.age})</>}
                  {i < birthdays.length - 1 && ', '}
                </span>
              ))}
            </strong>
          </p>
        </div>
      )}

      {/* Statistics */}
      <div className="stat-block statistics">
        <h3>Statistics</h3>
        <p>
          Total posts <strong>{stats.total_posts}</strong>
          {' • '}Total topics <strong>{stats.total_topics}</strong>
          {' • '}Total members <strong>{stats.total_users}</strong>
          {' • '}Our newest member{' '}
          <a
            href={`#user-${stats.newest_user}`}
            className="username"
            style={stats.newest_user_colour ? { color: `#${stats.newest_user_colour}`, fontWeight: 'bold' } : {}}
          >
            {stats.newest_user}
          </a>
        </p>
      </div>
    </div>
  );
}
