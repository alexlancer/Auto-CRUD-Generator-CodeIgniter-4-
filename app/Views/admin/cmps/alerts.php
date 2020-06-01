<?php $session = session(); ?>
<?php if ($session->get('success') || $session->get('danger') || $session->get('warning') || $session->get('info')) : ?>
    <div class="row">
        <div class="col-md-12">
            <?php if ($session->get('success')) : ?>
                <div class="alert alert-success"> <i class="far fa-user"></i> <?= $session->get('success') ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"> <span aria-hidden="true">×</span> </button>
                </div>
            <?php endif; ?>
            <?php if ($session->get('warning')) : ?>
                <div class="alert alert-warning"> <i class="far fa-user"></i> <?= $session->get('warning') ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"> <span aria-hidden="true">×</span> </button>
                </div>
            <?php endif; ?>
            <?php if ($session->get('danger')) : ?>
                <div class="alert alert-danger"> <i class="far fa-user"></i> <?= $session->get('danger') ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"> <span aria-hidden="true">×</span> </button>
                </div>
            <?php endif; ?>
            <?php if ($session->get('info')) : ?>
                <div class="alert alert-info"> <i class="far fa-user"></i> <?= $session->get('info') ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"> <span aria-hidden="true">×</span> </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>