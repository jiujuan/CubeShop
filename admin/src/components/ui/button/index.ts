import { cva, type VariantProps } from 'class-variance-authority'

export { default as Button } from './Button.vue'

export const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1677ff]/40 disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        default: 'bg-[#1677ff] text-white hover:bg-[#4096ff] active:bg-[#0958d9]',
        secondary: 'bg-slate-100 text-slate-700 hover:bg-slate-200',
        outline: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
        ghost: 'text-slate-700 hover:bg-slate-100',
        destructive: 'bg-red-500 text-white hover:bg-red-600',
        link: 'text-[#1677ff] underline-offset-4 hover:underline',
      },
      size: {
        default: 'h-8 px-3', // 紧凑高度，贴合高信息密度后台
        sm: 'h-7 px-2',
        lg: 'h-10 px-5',
        icon: 'h-8 w-8',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  },
)

export type ButtonVariants = VariantProps<typeof buttonVariants>
