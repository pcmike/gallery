  <?= video_tile_css() ?>
  .card:hover .tile-video { transform: scale(1.05); }
  .card.sold img, .card.sold .tile-video { filter: grayscale(1) brightness(0.6); }
  .sold-badge-corner, .reserved-badge-corner {
    position: absolute;
    top: 6px;
    left: 6px;
  }
  .card-badges {
    position: absolute;
    bottom: 6px;
    right: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  <?= ui_icon_css() ?>
