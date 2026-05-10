<?php 
$this->assign('title', 'Iniciar Sesión');
?>
<div class="d-flex align-items-center justify-content-center" style="min-height: 100dvh; background-color: var(--bg-color);">
    <section class="shadow overflow-hidden bg-white d-flex rounded-3 border" style="width: 600px; max-width: 95%; height: 500px; max-height: 95%; overflow: hidden;">
        <div class="w-25 position-relative" style="background: linear-gradient(135deg, var(--bg-color) 0%, #e9ecef 100%);">
            <!-- Círculos decorativos animados -->
            <div class="login-circles">
                <!-- Círculos grandes -->
                <div class="circle circle-orange" style="width: 120px; height: 120px; top: -30px; left: -30px;"></div>
                <div class="circle circle-green" style="width: 90px; height: 90px; top: 180px; left: -20px; animation-delay: 0.5s;"></div>
                <div class="circle circle-brown" style="width: 100px; height: 100px; bottom: -20px; left: 0px; animation-delay: 1s;"></div>
                <div class="circle circle-green" style="width: 110px; height: 110px; top: 320px; right: -35px; animation-delay: 1.3s;"></div>

                <!-- Círculos medianos -->
                <div class="circle circle-orange" style="width: 50px; height: 50px; top: 100px; right: 10px; animation-delay: 0.3s;"></div>
                <div class="circle circle-green" style="width: 60px; height: 60px; top: 120px; right: -10px; animation-delay: 0.8s;"></div>
                <div class="circle circle-brown" style="width: 45px; height: 45px; top: 180px; left: 40px; animation-delay: 1.2s;"></div>
                <div class="circle circle-orange" style="width: 55px; height: 55px; top: 420px; left: 55px; animation-delay: 2.1s;"></div>

                <!-- Círculos pequeños -->
                <div class="circle circle-orange" style="width: 25px; height: 25px; top: 60px; left: 50px; animation-delay: 0.6s;"></div>
                <div class="circle circle-green" style="width: 30px; height: 30px; top: 250px; right: 20px; animation-delay: 1.5s;"></div>
                <div class="circle circle-brown" style="width: 20px; height: 20px; bottom: 200px; left: 60px; animation-delay: 0.9s;"></div>
                <div class="circle circle-orange" style="width: 35px; height: 35px; bottom: 60px; right: 35px; animation-delay: 1.8s;"></div>
                <div class="circle circle-brown" style="width: 28px; height: 28px; top: 140px; left: 25px; animation-delay: 2.3s;"></div>
            </div>

            <?= $this->Html->css('login', ['block' => 'css']) ?>
        </div>
        <div class="d-flex flex-column justify-content-between w-75 p-5 pb-2">
            <div class="">
                <img src="<?= $this->Url->image('logos/logo-horizontal.svg') ?>" alt="">
                <p class="text-muted fw-light mt-1">Inicia sesión para continuar</p>
            </div>
            <?= $this->Flash->render() ?>
            <!-- Loading Spinner -->
            <?= $this->element('loading_spinner', ['message' => 'Iniciando sesión...']) ?>

            <div class="">
                <?= $this->Form->create(null) ?>
                    <div class="mb-3">
                        <label for="email" class="form-label fw-normal">
                            Correo Electrónico
                        </label>
                        <?= $this->Form->email('email', [
                            'class' => 'form-control',
                            'id' => 'email',
                            'placeholder' => 'ejemplo@correo.com',
                            'required' => true,
                            'autofocus' => true,
                            'class' => 'form-control shadow-none fw-normal'
                        ]) ?>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-normal">
                            Contraseña
                        </label>
                        <?= $this->Form->password('password', [
                            'class' => 'form-control',
                            'id' => 'password',
                            'placeholder' => '••••••••',
                            'required' => true,
                            'class' => 'form-control shadow-none fw-normal'
                        ]) ?>
                    </div>

                    <?= $this->Form->button('Iniciar Sesión', [
                        'class' => 'btn text-white fw-semibold shadow-sm',
                        'style' => 'background-color: #CD6A15;',
                        'escape' => false,
                    ]) ?>
                <?= $this->Form->end() ?>
            </div>
            <div class="text-center mt-5">
                <small class="fw-light d-flex flex-column gap-0" style="font-size: 13px;">
                    &copy; <?= date('Y') ?> Compañía Operadora Portuaria Cafetera S.A.<br> Todos los derechos reservados.
                </small>
            </div>
        </div>
    </section>

    <?= $this->Html->script('login') ?>
</div>