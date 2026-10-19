import {
  ChangeDetectionStrategy,
  Component,
  inject,
  signal
} from '@angular/core';

import {
  NonNullableFormBuilder,
  ReactiveFormsModule,
  Validators
} from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { ButtonComponent } from '../../../../../../../../shared/components/button/button.component';


interface BusinessType {

  label: string;

  value: string;

}

@Component({
  selector: 'app-contact-form',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    ButtonComponent
  ],
  templateUrl: './contact-form.component.html',
  styleUrl: './contact-form.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class ContactFormComponent {

  /* ==========================================================
     DEPENDENCIES
  ========================================================== */

  private readonly fb = inject(NonNullableFormBuilder);
  private readonly http = inject(HttpClient);

  /* ==========================================================
     STATE
  ========================================================== */

  readonly submitting = signal(false);
  readonly submitStatus = signal<'idle' | 'success' | 'error'>('idle');

  /* ==========================================================
     DATA
  ========================================================== */

  readonly businessTypes = signal<BusinessType[]>([

    {
      label: 'Restaurant & Café',
      value: 'restaurant'
    },

    {
      label: 'Retail Store',
      value: 'retail'
    },

    {
      label: 'Clothing Store',
      value: 'clothing'
    },

    {
      label: 'E-commerce',
      value: 'ecommerce'
    },

    {
      label: 'Service Business',
      value: 'service'
    },

    {
      label: 'Other',
      value: 'other'
    }

  ]);

  /* ==========================================================
     FORM
  ========================================================== */

  readonly contactForm = this.fb.group({

    fullName: this.fb.control('', [

      Validators.required,

      Validators.minLength(3),

      Validators.maxLength(100)

    ]),

    businessName: this.fb.control('', [

      Validators.required,

      Validators.maxLength(100)

    ]),

    phone: this.fb.control('', [

      Validators.required,

      Validators.pattern(/^[0-9+\-\s()]{8,20}$/)

    ]),

    email: this.fb.control('', [

      Validators.required,

      Validators.email

    ]),

    businessType: this.fb.control('', [

      Validators.required

    ]),

    message: this.fb.control('', [

      Validators.required,

      Validators.minLength(10),

      Validators.maxLength(500)

    ])

  });

  /* ==========================================================
     GETTERS
  ========================================================== */

  get f() {

    return this.contactForm.controls;

  }

  /* ==========================================================
     ACTIONS
  ========================================================== */

  submit(): void {

    if (this.contactForm.invalid || this.submitting()) {

      this.contactForm.markAllAsTouched();

      return;

    }

    const raw = this.contactForm.getRawValue();

    // Backend expects snake_case field names.
    const payload = {
      full_name: raw.fullName,
      business_name: raw.businessName,
      phone: raw.phone,
      email: raw.email,
      business_type: raw.businessType,
      message: raw.message
    };

    this.submitting.set(true);
    this.submitStatus.set('idle');

    this.http.post('/api/v1/demo-request', payload).subscribe({
      next: () => {
        this.submitting.set(false);
        this.submitStatus.set('success');
        this.contactForm.reset();
      },
      error: () => {
        this.submitting.set(false);
        this.submitStatus.set('error');
      }
    });

  }

}