import { FormEvent, useMemo, useState } from 'react'
import {
  Plus,
  RefreshCw,
  Save,
  Trash2,
  X,
} from 'lucide-react'
import {
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import {
  createSubcategory,
  deactivateSubcategory,
  fetchCategories,
  fetchSubcategories,
  updateSubcategory,
} from '../api/catalog'
import { ApiClientError } from '../api/client'
import {
  DesktopButton,
  DesktopPanel,
  Field,
  FormGroup,
} from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import {
  useWorkspace,
  useWorkspaceHandlers,
} from '../features/workspace/WorkspaceProvider'
import type { Subcategory } from '../types/catalog'

export function SubcategoriesPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()

  const canManage =
    useCan('categories.manage')

  const canCreate =
    useCan('categories.create') ||
    canManage

  const canEdit =
    useCan('categories.edit') ||
    canManage

  const canDelete =
    useCan('categories.delete') ||
    canManage

  const categories = useQuery({
    queryKey: ['categories'],
    queryFn: fetchCategories,
  })

  const query = useQuery({
    queryKey: ['subcategories'],
    queryFn: () =>
      fetchSubcategories(),
  })

  const rows = useMemo(
    () => query.data ?? [],
    [query.data],
  )

  const [selectedKey, setSelectedKey] =
    useState<string | null>(null)

  const [categoryUlid, setCategoryUlid] =
    useState('')

  const [code, setCode] =
    useState('')

  const [name, setName] =
    useState('')

  const [error, setError] =
    useState<string | null>(null)

  const selected =
    rows.find(
      (row) =>
        row.ulid === selectedKey,
    ) ?? null

  const defaultCategoryUlid =
    categories.data?.find(
      (category) =>
        category.is_active,
    )?.ulid ??
    categories.data?.[0]?.ulid ??
    ''

  const canSave =
    selectedKey
      ? canEdit
      : canCreate

  function select(
    row: Subcategory,
  ) {
    setSelectedKey(row.ulid)

    setCategoryUlid(
      row.category?.ulid ??
        row.category_ulid ??
        '',
    )

    setCode(row.code)
    setName(row.name)
    setError(null)
  }

  function startNew() {
    setSelectedKey(null)
    setCategoryUlid(
      defaultCategoryUlid,
    )
    setCode('')
    setName('')
    setError(null)
  }

  const saveMutation =
    useMutation({
      mutationFn: async () => {
        const payload = {
          category_ulid:
            categoryUlid ||
            defaultCategoryUlid,

          code:
            code.trim(),

          name:
            name.trim(),
        }

        if (selectedKey) {
          return updateSubcategory(
            selectedKey,
            payload,
          )
        }

        return createSubcategory(
          payload,
        )
      },

      onSuccess: async (item) => {
        await queryClient.invalidateQueries({
          queryKey: [
            'subcategories',
          ],
        })

        select(item)
      },
    })

  const deactivateMutation =
    useMutation({
      mutationFn: async () => {
        if (!selectedKey) {
          return null
        }

        return deactivateSubcategory(
          selectedKey,
        )
      },

      onSuccess: async () => {
        await queryClient.invalidateQueries({
          queryKey: [
            'subcategories',
          ],
        })
      },
    })

  const activateMutation =
    useMutation({
      mutationFn: async () => {
        if (!selectedKey) {
          return null
        }

        return updateSubcategory(
          selectedKey,
          {
            is_active: true,
          },
        )
      },

      onSuccess: async (item) => {
        await queryClient.invalidateQueries({
          queryKey: [
            'subcategories',
          ],
        })

        if (item) {
          select(item)
        }
      },
    })

  async function onSubmit(
    event: FormEvent,
  ) {
    event.preventDefault()
    setError(null)

    if (
      !(
        categoryUlid ||
        defaultCategoryUlid
      )
    ) {
      setError(
        'Please create or select a category first.',
      )

      return
    }

    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(
        err instanceof ApiClientError
          ? err.message
          : 'Unable to save subcategory.',
      )
    }
  }

  useWorkspaceHandlers({
    save: () =>
      (
        document.getElementById(
          'subcategory-form',
        ) as HTMLFormElement | null
      )?.requestSubmit(),

    refresh: () =>
      void query.refetch(),
  })

  return (
    <DesktopPanel
      title="Subcategories"
      toolbar={
        <>
          <DesktopButton
            icon={<Plus size={13} />}
            label="New"
            disabled={!canCreate}
            onClick={startNew}
          />

          <DesktopButton
            icon={<Save size={13} />}
            label="Save"
            shortcut="F9"
            disabled={
              !canSave ||
              saveMutation.isPending
            }
            onClick={() =>
              (
                document.getElementById(
                  'subcategory-form',
                ) as HTMLFormElement | null
              )?.requestSubmit()
            }
          />

          <DesktopButton
            icon={<Trash2 size={13} />}
            label="Delete"
            disabled={
              !canDelete ||
              !selected?.is_active ||
              deactivateMutation.isPending
            }
            onClick={() => {
              if (
                !selected ||
                !window.confirm(
                  `Deactivate ${selected.name}?`,
                )
              ) {
                return
              }

              void deactivateMutation
                .mutateAsync()
                .catch((err) => {
                  setError(
                    err instanceof ApiClientError
                      ? err.message
                      : 'Unable to deactivate subcategory.',
                  )
                })
            }}
          />

          <DesktopButton
            icon={<RefreshCw size={13} />}
            label="Activate"
            disabled={
              !canEdit ||
              !selected ||
              selected.is_active ||
              activateMutation.isPending
            }
            onClick={() => {
              void activateMutation
                .mutateAsync()
                .catch((err) => {
                  setError(
                    err instanceof ApiClientError
                      ? err.message
                      : 'Unable to activate subcategory.',
                  )
                })
            }}
          />

          <DesktopButton
            icon={<RefreshCw size={13} />}
            label="Refresh"
            shortcut="F8"
            onClick={() =>
              void query.refetch()
            }
          />

          <DesktopButton
            icon={<X size={13} />}
            label="Close"
            shortcut="Esc"
            onClick={closeActiveTab}
          />
        </>
      }
    >
      {error ? (
        <p className="mb-2 text-[12px] text-[var(--danger)]">
          {error}
        </p>
      ) : null}

      {canCreate || canEdit ? (
        <form
          id="subcategory-form"
          onSubmit={onSubmit}
        >
          <FormGroup
            title={
              selected
                ? 'Edit subcategory'
                : 'New subcategory'
            }
          >
            <Field label="Category">
              <select
                className="desktop-select"
                value={
                  categoryUlid ||
                  defaultCategoryUlid
                }
                onChange={(e) =>
                  setCategoryUlid(
                    e.target.value,
                  )
                }
                required
              >
                {(categories.data ?? []).map(
                  (category) => (
                    <option
                      key={category.ulid}
                      value={category.ulid}
                    >
                      {category.name}
                      {category.is_active
                        ? ''
                        : ' (Archived)'}
                    </option>
                  ),
                )}
              </select>
            </Field>

            <Field label="Code">
              <input
                className="desktop-input"
                value={code}
                onChange={(e) =>
                  setCode(
                    e.target.value,
                  )
                }
                required
              />
            </Field>

            <Field label="Name">
              <input
                className="desktop-input"
                value={name}
                onChange={(e) =>
                  setName(
                    e.target.value,
                  )
                }
                required
              />
            </Field>
          </FormGroup>
        </form>
      ) : null}

      <div
        style={{
          height: 380,
          marginTop: 6,
        }}
      >
        <PosDataGrid
          columns={[
            {
              key: 'category',
              header: 'Category',
              render: (row) =>
                row.category?.name ?? '',
            },

            {
              key: 'code',
              header: 'Code',
              width: 120,
              render: (row) =>
                row.code,
            },

            {
              key: 'name',
              header: 'Name',
              render: (row) =>
                row.name,
            },

            {
              key: 'status',
              header: 'Status',
              width: 100,
              render: (row) =>
                row.is_active
                  ? 'Active'
                  : 'Archived',
            },
          ]}
          rows={rows}
          rowKey={(row) =>
            row.ulid
          }
          selectedKey={
            selectedKey
          }
          onSelect={select}
        />
      </div>
    </DesktopPanel>
  )
}