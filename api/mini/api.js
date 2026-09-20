const API_BASE = 'https://predictions.infinityfreeapp.com/api/mini'

async function request(path, { method = 'GET', body } = {}) {
  let response

  try {
    response = await fetch(`${API_BASE}/${path}`, {
      method,
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    })
  } catch (err) {
    throw new Error('Server error. Please try again.')
  }

  let data

  try {
    data = await response.json()
  } catch (err) {
    throw new Error('Server error. Please try again.')
  }

  if (!data.success) {
    const error = new Error(data.message || 'Something went wrong.')
    error.status = response.status
    throw error
  }

  return data.data
}

export function login(identifier, password) {
  return request('login.php', {
    method: 'POST',
    body: { identifier, password },
  })
}

export function logout() {
  return request('logout.php', { method: 'POST' })
}

export function getMe() {
  return request('me.php')
}

export function getPredictions() {
  return request('predictions.php')
}

export function submitPredictions(gameweek, predictions) {
  return request('submit_predictions.php', {
    method: 'POST',
    body: { gameweek, predictions },
  })
}

export function getLeaderboard() {
  return request('leaderboard.php')
}