export type BrowserLocationEvidence = {
  location_latitude: number
  location_longitude: number
  location_accuracy: number
}

/**
 * Browser permission must be granted before every interactive sign-in.
 * Coordinates are not cryptographic proof of the person's physical presence.
 */
export async function requireBrowserLocation(): Promise<BrowserLocationEvidence> {
  if (!window.isSecureContext || !navigator.geolocation) {
    throw new Error('Sign-in requires browser location access on HTTPS (or localhost).')
  }
  let position: GeolocationPosition
  try {
    position = await new Promise<GeolocationPosition>((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 0,
      })
    })
  } catch {
    throw new Error('Location access is required to sign in. Enable location for BluePOS in your browser and try again.')
  }
  if (!Number.isFinite(position.coords.latitude)
      || !Number.isFinite(position.coords.longitude)
      || !Number.isFinite(position.coords.accuracy)
      || position.coords.accuracy < 0) {
    throw new Error('Browser location is unavailable. Enable location permission and retry.')
  }
  return {
    location_latitude: position.coords.latitude,
    location_longitude: position.coords.longitude,
    location_accuracy: position.coords.accuracy,
  }
}
