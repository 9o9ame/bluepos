import type { Product } from '../../types/catalog'
import { ProductsPage } from '../../pages/ProductsPage'
import { UiModal } from '../ui/UiModal'
import './ProductModal.css'

export function ProductModal({
  open = true,
  onClose,
  onSaved,
}: {
  open?: boolean
  onClose: () => void
  onSaved: (product: Product) => void
}) {
  return (
    <UiModal
      open={open}
      title="Products"
      ariaLabel="Products Definition"
      onClose={onClose}
      size="xl"
      className="product-modal product-definition-modal"
      bodyClassName="product-definition-modal-body"
    >
      <ProductsPage embedded onClose={onClose} onSaved={onSaved} />
    </UiModal>
  )
}
