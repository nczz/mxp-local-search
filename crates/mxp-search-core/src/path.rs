use std::path::{Component, Path, PathBuf};
use std::{fs, io};

use crate::error::{Error, IoContext, Result};

pub(crate) fn ensure_root(root: &Path) -> Result<PathBuf> {
    fs::create_dir_all(root).at(root)?;
    canonical_no_symlink(root)
}

pub(crate) fn resolve_new_kb_path(root: &Path, path: &Path) -> Result<PathBuf> {
    resolve_kb_path(root, path, true)
}

pub(crate) fn resolve_existing_kb_path(root: &Path, path: &Path) -> Result<PathBuf> {
    resolve_kb_path(root, path, false)
}

fn resolve_kb_path(root: &Path, path: &Path, create_parent: bool) -> Result<PathBuf> {
    if path
        .components()
        .any(|component| matches!(component, Component::ParentDir))
    {
        return Err(Error::OutsideRoot {
            path: path.to_path_buf(),
        });
    }
    if create_parent && path.is_absolute() {
        let root_prefix = absolute_configured_root(root)?;
        if !path.starts_with(&root_prefix) {
            return Err(Error::OutsideRoot {
                path: path.to_path_buf(),
            });
        }
    }

    let root = if create_parent {
        ensure_root(root)?
    } else {
        canonical_no_symlink(root)?
    };
    let candidate = if path.is_absolute() {
        path.to_path_buf()
    } else {
        root.join(path)
    };

    if candidate
        .components()
        .any(|component| matches!(component, Component::ParentDir))
    {
        return Err(Error::OutsideRoot { path: candidate });
    }

    let parent = candidate.parent().unwrap_or(&root);
    let ancestor = nearest_existing_ancestor(parent).ok_or_else(|| Error::OutsideRoot {
        path: candidate.clone(),
    })?;
    let ancestor = canonical_no_symlink(&ancestor)?;
    if !ancestor.starts_with(&root) {
        return Err(Error::OutsideRoot { path: candidate });
    }
    reject_symlink_chain(&root, &ancestor)?;
    if create_parent {
        fs::create_dir_all(parent).at(parent)?;
        let parent = canonical_no_symlink(parent)?;
        if !parent.starts_with(&root) {
            return Err(Error::OutsideRoot { path: candidate });
        }
    }

    reject_symlink_chain(&root, &candidate)?;

    if candidate.exists() {
        let resolved = canonical_no_symlink(&candidate)?;
        if !resolved.starts_with(&root) {
            return Err(Error::OutsideRoot { path: candidate });
        }
        Ok(resolved)
    } else {
        Ok(candidate)
    }
}

fn absolute_configured_root(root: &Path) -> Result<PathBuf> {
    if root.is_absolute() {
        Ok(root.to_path_buf())
    } else {
        Ok(std::env::current_dir().at(root)?.join(root))
    }
}

fn nearest_existing_ancestor(path: &Path) -> Option<PathBuf> {
    let mut current = path;
    loop {
        if current.exists() {
            return Some(current.to_path_buf());
        }
        current = current.parent()?;
    }
}

fn canonical_no_symlink(path: &Path) -> Result<PathBuf> {
    if fs::symlink_metadata(path)
        .at(path)?
        .file_type()
        .is_symlink()
    {
        return Err(Error::SymlinkRejected {
            path: path.to_path_buf(),
        });
    }
    fs::canonicalize(path).at(path)
}

fn reject_symlink_chain(root: &Path, candidate: &Path) -> Result<()> {
    let mut current = PathBuf::new();
    for component in candidate.components() {
        current.push(component.as_os_str());
        if current.starts_with(root) && current.exists() {
            match fs::symlink_metadata(&current) {
                Ok(metadata) if metadata.file_type().is_symlink() => {
                    return Err(Error::SymlinkRejected { path: current });
                }
                Ok(_) => {}
                Err(err) if err.kind() == io::ErrorKind::NotFound => {}
                Err(source) => {
                    return Err(Error::Io {
                        path: current,
                        source,
                    })
                }
            }
        }
    }
    Ok(())
}

pub(crate) fn atomic_write(path: &Path, bytes: &[u8]) -> Result<()> {
    let tmp = path.with_extension(format!("tmp-{}", uuid::Uuid::new_v4().as_simple()));
    fs::write(&tmp, bytes).at(&tmp)?;
    fs::rename(&tmp, path).at(path)
}
