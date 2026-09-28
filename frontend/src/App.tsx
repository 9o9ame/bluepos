import { Route, Routes } from 'react-router-dom'
import { PwaUpdateBanner } from './components/desktop/PwaUpdateBanner'
import { AuthProvider } from './features/auth/AuthProvider'
import { PlatformAuthProvider } from './features/platform/PlatformAuthProvider'
import { AppRoutes } from './routes'
import { PlatformRoutes } from './routes/platform'

export default function App() {
  return (
    <>
      <PwaUpdateBanner />
      <Routes>
        <Route
          path="/platform/*"
          element={
            <PlatformAuthProvider>
              <PlatformRoutes />
            </PlatformAuthProvider>
          }
        />
        <Route
          path="*"
          element={
            <AuthProvider>
              <AppRoutes />
            </AuthProvider>
          }
        />
      </Routes>
    </>
  )
}
